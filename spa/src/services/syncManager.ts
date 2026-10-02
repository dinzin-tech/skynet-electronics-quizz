import { offlineDB } from './db';
import { api } from './api';

export type SyncStatus = 'synced' | 'saving' | 'offline' | 'error';
type StatusListener = (status: SyncStatus) => void;

class SyncManager {
  private attemptId: string | null = null;
  private currentSeq = 0;
  private debounceTimer: any = null;
  private intervalTimer: any = null;
  private isFlushing = false;
  private status: SyncStatus = 'synced';
  private listeners: Set<StatusListener> = new Set();

  constructor() {
    if (typeof window !== 'undefined') {
      window.addEventListener('online', () => this.flush());
      window.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
          this.flush();
        }
      });
    }
  }

  public async init(attemptId: string, initialServerMaxSeq: number): Promise<void> {
    this.attemptId = attemptId;
    const localMaxSeq = await offlineDB.getHighestLocalSeq(attemptId);
    this.currentSeq = Math.max(initialServerMaxSeq, localMaxSeq);

    // Periodic sweep every 10 seconds
    if (this.intervalTimer) {
      clearInterval(this.intervalTimer);
    }
    this.intervalTimer = setInterval(() => {
      this.flush();
    }, 10000);

    // Initial check for any pending unacked answers
    this.flush();
  }

  public destroy(): void {
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
    if (this.intervalTimer) {
      clearInterval(this.intervalTimer);
    }
    this.attemptId = null;
    this.currentSeq = 0;
  }

  public onStatusChange(listener: StatusListener): () => void {
    this.listeners.add(listener);
    listener(this.status);
    return () => this.listeners.delete(listener);
  }

  private setStatus(status: SyncStatus): void {
    if (this.status !== status) {
      this.status = status;
      this.listeners.forEach((l) => l(status));
    }
  }

  /**
   * Called whenever user chooses an option.
   */
  public async queueAnswer(qid: number, oid: number): Promise<number> {
    if (!this.attemptId) {
      throw new Error('SyncManager not initialized with attemptId');
    }

    this.currentSeq += 1;
    const seq = this.currentSeq;

    // 1. Immediately write to IndexedDB (durable client copy)
    await offlineDB.saveAnswer(this.attemptId, qid, oid, seq);

    this.setStatus('saving');

    // 2. Debounce HTTP PUT request by 400ms
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }

    this.debounceTimer = setTimeout(() => {
      this.flush();
    }, 400);

    return seq;
  }

  /**
   * Flush unacked items to server.
   */
  public async flush(): Promise<void> {
    if (!this.attemptId || this.isFlushing) {
      return;
    }

    if (!navigator.onLine) {
      this.setStatus('offline');
      return;
    }

    this.isFlushing = true;
    try {
      const unacked = await offlineDB.getUnackedAnswers(this.attemptId);
      if (unacked.length === 0) {
        this.setStatus('synced');
        this.isFlushing = false;
        return;
      }

      this.setStatus('saving');

      // Send batch of unacked items (up to 100 items per chunk)
      const chunk = unacked.slice(0, 100);
      const res = await api.saveAnswers(this.attemptId, chunk);

      if (res && res.ok) {
        // Mark acked up to returned max_seq
        await offlineDB.markAckedUpTo(this.attemptId, res.max_seq);

        // Check if there are still remaining unacked items
        const remaining = await offlineDB.getUnackedAnswers(this.attemptId);
        if (remaining.length > 0) {
          // Trigger next chunk
          setTimeout(() => this.flush(), 100);
        } else {
          this.setStatus('synced');
        }
      }
    } catch (e: any) {
      if (!navigator.onLine) {
        this.setStatus('offline');
      } else {
        this.setStatus('error');
      }
    } finally {
      this.isFlushing = false;
    }
  }
}

export const syncManager = new SyncManager();
