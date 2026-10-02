import { SaveItem } from '../types';

const DB_NAME = 'corp_quiz_offline_db';
const DB_VERSION = 1;
const STORE_ANSWERS = 'answers';

interface StoredAnswer {
  key: string; // `${attempt_id}:${q}`
  attempt_id: string;
  q: number;
  o: number;
  seq: number;
  acked: number; // 0 = unacked, 1 = acked
  updated_at: number;
}

class OfflineDatabase {
  private dbPromise: Promise<IDBDatabase> | null = null;

  private getDB(): Promise<IDBDatabase> {
    if (!this.dbPromise) {
      this.dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
          const db = req.result;
          if (!db.objectStoreNames.contains(STORE_ANSWERS)) {
            const store = db.createObjectStore(STORE_ANSWERS, { keyPath: 'key' });
            store.createIndex('attempt_id', 'attempt_id', { unique: false });
            store.createIndex('acked', 'acked', { unique: false });
          }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
      });
    }
    return this.dbPromise;
  }

  public async saveAnswer(attemptId: string, q: number, o: number, seq: number): Promise<void> {
    const db = await this.getDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ANSWERS, 'readwrite');
      const store = tx.objectStore(STORE_ANSWERS);
      const record: StoredAnswer = {
        key: `${attemptId}:${q}`,
        attempt_id: attemptId,
        q,
        o,
        seq,
        acked: 0,
        updated_at: Date.now(),
      };
      const req = store.put(record);
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error);
    });
  }

  public async getUnackedAnswers(attemptId: string): Promise<SaveItem[]> {
    const db = await this.getDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ANSWERS, 'readonly');
      const store = tx.objectStore(STORE_ANSWERS);
      const req = store.getAll();
      req.onsuccess = () => {
        const records = (req.result as StoredAnswer[]).filter(
          (r) => r.attempt_id === attemptId && r.acked === 0
        );
        records.sort((a, b) => a.seq - b.seq);
        resolve(records.map((r) => ({ q: r.q, o: r.o, seq: r.seq, ts: r.updated_at })));
      };
      req.onerror = () => reject(req.error);
    });
  }

  public async markAckedUpTo(attemptId: string, maxSeq: number): Promise<void> {
    const db = await this.getDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ANSWERS, 'readwrite');
      const store = tx.objectStore(STORE_ANSWERS);
      const req = store.getAll();
      req.onsuccess = () => {
        const records = (req.result as StoredAnswer[]).filter(
          (r) => r.attempt_id === attemptId && r.seq <= maxSeq && r.acked === 0
        );
        for (const rec of records) {
          rec.acked = 1;
          store.put(rec);
        }
        resolve();
      };
      req.onerror = () => reject(req.error);
    });
  }

  public async getAllAnswers(attemptId: string): Promise<Record<string, { o: number; seq: number }>> {
    const db = await this.getDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ANSWERS, 'readonly');
      const store = tx.objectStore(STORE_ANSWERS);
      const req = store.getAll();
      req.onsuccess = () => {
        const result: Record<string, { o: number; seq: number }> = {};
        const records = (req.result as StoredAnswer[]).filter((r) => r.attempt_id === attemptId);
        for (const r of records) {
          result[r.q.toString()] = { o: r.o, seq: r.seq };
        }
        resolve(result);
      };
      req.onerror = () => reject(req.error);
    });
  }

  public async getHighestLocalSeq(attemptId: string): Promise<number> {
    const db = await this.getDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ANSWERS, 'readonly');
      const store = tx.objectStore(STORE_ANSWERS);
      const req = store.getAll();
      req.onsuccess = () => {
        const records = (req.result as StoredAnswer[]).filter((r) => r.attempt_id === attemptId);
        let max = 0;
        for (const r of records) {
          if (r.seq > max) {
            max = r.seq;
          }
        }
        resolve(max);
      };
      req.onerror = () => reject(req.error);
    });
  }
}

export const offlineDB = new OfflineDatabase();
