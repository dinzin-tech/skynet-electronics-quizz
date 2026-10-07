<?php

declare(strict_types=1);

namespace App\Commands;

use App\Hot\Redis as HotRedis;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class QuizFlusherCommand
{
    private PDO $db;
    /** @var mixed */
    private $redis;
    private bool $running = true;

    public function __construct(?PDO $db = null, $redis = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->redis = $redis;
    }

    public function execute(array $args = []): void
    {
        $once = in_array('--once', $args, true);
        $redis = $this->getRedis();

        if (!$redis) {
            echo "Redis is not available. Flusher cannot run.\n";
            return;
        }

        echo "QuizFlusher started" . ($once ? " (single pass)" : " (daemon mode)") . "...\n";

        // Recover orphaned items left in processing sets on startup
        $this->recoverProcessingSets($redis);

        $lastRecoveryTime = time();

        do {
            try {
                $redis->setEx('worker:heartbeat:flusher', 30, (string) time());
            } catch (\Throwable $e) {
                // Ignore transient heartbeat failure
            }

            // Periodically re-queue orphaned processing items every 30 seconds
            if (time() - $lastRecoveryTime >= 30) {
                $this->recoverProcessingSets($redis);
                $lastRecoveryTime = time();
            }

            $flushedAnswers = $this->flushAnswers($redis);
            $flushedAttempts = $this->flushAttempts($redis);

            if ($flushedAnswers > 0 || $flushedAttempts > 0) {
                echo "[Flusher] Flushed {$flushedAnswers} answer(s), {$flushedAttempts} attempt(s).\n";
            }

            if ($once) {
                break;
            }

            usleep(200000); // 200ms sleep
        } while ($this->running);
    }

    /**
     * Flush up to 500 dirty answers to MySQL attempt_answers.
     *
     * @param mixed $redis
     */
    public function flushAnswers($redis, int $limit = 500): int
    {
        // Pop up to $limit dirty answer keys and track in processing set
        $members = method_exists($redis, 'sPop') ? $redis->sPop('dirty', $limit) : [];
        if (empty($members)) {
            return 0;
        }

        if (is_string($members)) {
            $members = [$members];
        }

        if (method_exists($redis, 'sAdd')) {
            foreach ($members as $item) {
                $redis->sAdd('dirty:processing', (string) $item);
            }
        }

        $rows = [];
        $bindings = [];

        foreach ($members as $item) {
            $parts = explode(':', $item, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$aid, $qid] = $parts;

            $record = $redis->hGet("ans:{$aid}", $qid);
            if (!$record) {
                continue;
            }

            // Record format: "oid|seq|ts_ms"
            $recParts = explode('|', $record);
            $oid = (int) ($recParts[0] ?? 0);
            $seq = (int) ($recParts[1] ?? 0);
            $tsMs = (int) ($recParts[2] ?? 0);

            // Get numeric attempt_id
            $numericAttId = (int) ($redis->hGet("att:{$aid}", 'id') ?: 0);
            if ($numericAttId === 0) {
                $stmt = $this->db->prepare('SELECT id FROM attempts WHERE public_id = :pid');
                $stmt->execute(['pid' => $aid]);
                $numericAttId = (int) $stmt->fetchColumn();
                if ($numericAttId > 0 && method_exists($redis, 'hSet')) {
                    $redis->hSet("att:{$aid}", 'id', (string) $numericAttId);
                }
            }

            if ($numericAttId === 0) {
                continue;
            }

            $answeredAt = $tsMs > 0
                ? (new DateTimeImmutable('@' . (int) ($tsMs / 1000)))->format('Y-m-d H:i:s') .
                  '.' . sprintf('%03d', $tsMs % 1000)
                : gmdate('Y-m-d H:i:s.000');

            $rows[] = '(?, ?, ?, ?, ?)';
            $bindings[] = $numericAttId;
            $bindings[] = (int) $qid;
            $bindings[] = $oid > 0 ? $oid : null;
            $bindings[] = $seq;
            $bindings[] = $answeredAt;
        }

        if (empty($rows)) {
            if (method_exists($redis, 'sRem')) {
                foreach ($members as $item) {
                    $redis->sRem('dirty:processing', (string) $item);
                }
            }
            return 0;
        }

        $sql = 'INSERT INTO attempt_answers ' .
               '(attempt_id, question_id, selected_option_id, seq, answered_at) ' .
               'VALUES ' . implode(', ', $rows) . ' ' .
               'ON DUPLICATE KEY UPDATE ' .
               'selected_option_id = IF(VALUES(seq) >= seq, VALUES(selected_option_id), selected_option_id), ' .
               'answered_at = IF(VALUES(seq) >= seq, VALUES(answered_at), answered_at), ' .
               'seq = IF(VALUES(seq) >= seq, VALUES(seq), seq)';

        $inTx = $this->db->inTransaction();
        if (!$inTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
            if (!$inTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Re-enqueue into dirty set so items are never lost on DB failure
            if (method_exists($redis, 'sAdd')) {
                foreach ($members as $item) {
                    $redis->sAdd('dirty', (string) $item);
                }
            }
            if (method_exists($redis, 'sRem')) {
                foreach ($members as $item) {
                    $redis->sRem('dirty:processing', (string) $item);
                }
            }
            throw $e;
        }

        // Successfully committed: remove from processing set
        if (method_exists($redis, 'sRem')) {
            foreach ($members as $item) {
                $redis->sRem('dirty:processing', (string) $item);
            }
        }

        return count($rows);
    }

    /**
     * Flush up to 500 dirty attempts to MySQL attempts.
     *
     * @param mixed $redis
     */
    public function flushAttempts($redis, int $limit = 500): int
    {
        $aids = method_exists($redis, 'sPop') ? $redis->sPop('dirty_att', $limit) : [];
        if (empty($aids)) {
            return 0;
        }

        if (is_string($aids)) {
            $aids = [$aids];
        }

        if (method_exists($redis, 'sAdd')) {
            foreach ($aids as $aid) {
                $redis->sAdd('dirty_att:processing', (string) $aid);
            }
        }

        $updateCount = 0;
        $upStmt = $this->db->prepare(
            'UPDATE attempts SET ' .
            'status = :status, ' .
            'started_at = :sat, ' .
            'deadline_at = :dat, ' .
            'submitted_at = :subat, ' .
            'submit_reason = :reason, ' .
            'layout = :layout, ' .
            'max_seq = :mseq, ' .
            'correct_count = :cor, ' .
            'score = :score, ' .
            'accuracy = :acc, ' .
            'completion_time_s = :ctime, ' .
            'graded_at = :gradat ' .
            'WHERE public_id = :pid'
        );

        $inTx = $this->db->inTransaction();
        if (!$inTx) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($aids as $aid) {
                $att = $redis->hGetAll("att:{$aid}");
                if (empty($att)) {
                    continue;
                }

                $startMs = (int) ($att['started_ms'] ?? 0);
                $deadlineMs = (int) ($att['deadline_ms'] ?? 0);
                $submittedMs = (int) ($att['submitted_ms'] ?? 0);

                $sat = $startMs > 0
                    ? (new DateTimeImmutable('@' . (int) ($startMs / 1000)))->format('Y-m-d H:i:s') .
                      '.' . sprintf('%03d', $startMs % 1000)
                    : null;
                $dat = $deadlineMs > 0
                    ? (new DateTimeImmutable('@' . (int) ($deadlineMs / 1000)))->format('Y-m-d H:i:s') .
                      '.' . sprintf('%03d', $deadlineMs % 1000)
                    : null;
                $subat = $submittedMs > 0
                    ? (new DateTimeImmutable('@' . (int) ($submittedMs / 1000)))->format('Y-m-d H:i:s') .
                      '.' . sprintf('%03d', $submittedMs % 1000)
                    : null;

                $isGraded = ($att['graded'] ?? '') === '1';
                $gradat = $isGraded ? gmdate('Y-m-d H:i:s.000') : null;

                $upStmt->execute([
                    'status' => $att['status'] ?? 'NOT_STARTED',
                    'sat' => $sat,
                    'dat' => $dat,
                    'subat' => $subat,
                    'reason' => !empty($att['reason']) ? $att['reason'] : null,
                    'layout' => !empty($att['layout']) ? $att['layout'] : null,
                    'mseq' => (int) ($att['max_seq'] ?? 0),
                    'cor' => $isGraded ? (int) ($att['correct'] ?? 0) : null,
                    'score' => $isGraded ? (float) ($att['score'] ?? 0) : null,
                    'acc' => $isGraded ? (float) ($att['accuracy'] ?? 0) : null,
                    'ctime' => $isGraded ? (int) ($att['time_s'] ?? 0) : null,
                    'gradat' => $gradat,
                    'pid' => $aid,
                ]);
                $updateCount++;
            }

            if (!$inTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Re-enqueue into dirty_att set so work is not lost
            if (method_exists($redis, 'sAdd')) {
                foreach ($aids as $aid) {
                    $redis->sAdd('dirty_att', (string) $aid);
                }
            }
            if (method_exists($redis, 'sRem')) {
                foreach ($aids as $aid) {
                    $redis->sRem('dirty_att:processing', (string) $aid);
                }
            }
            throw $e;
        }

        // Successfully committed: remove from processing set
        if (method_exists($redis, 'sRem')) {
            foreach ($aids as $aid) {
                $redis->sRem('dirty_att:processing', (string) $aid);
            }
        }

        return $updateCount;
    }

    /**
     * Recover orphaned items left in processing sets due to prior crashes.
     *
     * @param mixed $redis
     * @return array{answers_recovered: int, attempts_recovered: int}
     */
    public function recoverProcessingSets($redis): array
    {
        $recoveredAnswers = 0;
        $recoveredAttempts = 0;

        if (method_exists($redis, 'sMembers') && method_exists($redis, 'sAdd') && method_exists($redis, 'sRem')) {
            $staleAnswers = $redis->sMembers('dirty:processing') ?: [];
            foreach ($staleAnswers as $item) {
                $redis->sAdd('dirty', (string) $item);
                $redis->sRem('dirty:processing', (string) $item);
                $recoveredAnswers++;
            }

            $staleAttempts = $redis->sMembers('dirty_att:processing') ?: [];
            foreach ($staleAttempts as $aid) {
                $redis->sAdd('dirty_att', (string) $aid);
                $redis->sRem('dirty_att:processing', (string) $aid);
                $recoveredAttempts++;
            }
        }

        return [
            'answers_recovered' => $recoveredAnswers,
            'attempts_recovered' => $recoveredAttempts,
        ];
    }

    private function getRedis()
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        try {
            $this->redis = HotRedis::connection();
            return $this->redis;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
