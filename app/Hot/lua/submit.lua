-- submit.lua
-- Atomically submits an attempt, prevents duplicate submissions, and queues for grading.
-- KEYS[1] = att:{aid}
-- KEYS[2] = deadlines (zset: aid -> deadline_ms)
-- KEYS[3] = dirty_att (set: aid)
-- KEYS[4] = fq (list: aid)
-- KEYS[5] = qstat:{quizId} (hash)
-- ARGV[1] = aid (string)
-- ARGV[2] = now_ms (integer)
-- ARGV[3] = reason ('manual' | 'timeout' | 'admin')

local attKey = KEYS[1]
local deadlinesKey = KEYS[2]
local dirtyAttKey = KEYS[3]
local fqKey = KEYS[4]
local qstatKey = KEYS[5]

local aid = ARGV[1]
local nowMs = tonumber(ARGV[2])
local reason = ARGV[3] or 'manual'

local status = redis.call('HGET', attKey, 'status')
if not status then
    return cjson.encode({error = 'attempt_not_found'})
end

if status == 'COMPLETED' then
    local submittedMs = redis.call('HGET', attKey, 'submitted_ms')
    return cjson.encode({
        ok = true,
        already_completed = true,
        submitted_ms = tonumber(submittedMs),
        message = 'Attempt already submitted'
    })
end

if status == 'ABSENT' then
    return cjson.encode({error = 'attempt_marked_absent'})
end

redis.call('HMSET', attKey,
    'status', 'COMPLETED',
    'submitted_ms', tostring(nowMs),
    'submit_reason', reason
)

redis.call('ZREM', deadlinesKey, aid)
redis.call('SADD', dirtyAttKey, aid)
redis.call('RPUSH', fqKey, aid)

if redis.call('EXISTS', qstatKey) == 1 then
    if status == 'IN_PROGRESS' then
        redis.call('HINCRBY', qstatKey, 'in_progress', -1)
    end
    redis.call('HINCRBY', qstatKey, 'completed', 1)
end

return cjson.encode({
    ok = true,
    already_completed = false,
    submitted_ms = nowMs,
    submit_reason = reason
})
