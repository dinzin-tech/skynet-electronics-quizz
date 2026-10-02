-- start.lua
-- Atomically starts a quiz attempt and computes server deadline with overtime cap.
-- KEYS[1] = att:{aid}
-- KEYS[2] = quiz:{code}
-- KEYS[3] = deadlines (zset: aid -> deadline_ms)
-- KEYS[4] = dirty_att (set: aid)
-- KEYS[5] = qstat:{quizId} (hash)
-- ARGV[1] = now_ms
-- ARGV[2] = duration_ms
-- ARGV[3] = end_ms
-- ARGV[4] = overtime_grace_ms
-- ARGV[5] = layout (JSON string)
-- ARGV[6] = aid (string)

local attKey = KEYS[1]
local deadlinesKey = KEYS[3]
local dirtyAttKey = KEYS[4]
local qstatKey = KEYS[5]

local nowMs = tonumber(ARGV[1])
local durationMs = tonumber(ARGV[2])
local endMs = tonumber(ARGV[3])
local overtimeGraceMs = tonumber(ARGV[4])
local layout = ARGV[5]
local aid = ARGV[6]

local status = redis.call('HGET', attKey, 'status')
if not status then
    return cjson.encode({error = 'attempt_not_found'})
end

if status == 'IN_PROGRESS' then
    local startedMs = redis.call('HGET', attKey, 'started_ms')
    local deadlineMs = redis.call('HGET', attKey, 'deadline_ms')
    local existingLayout = redis.call('HGET', attKey, 'layout')
    return cjson.encode({
        status = 'IN_PROGRESS',
        started_ms = tonumber(startedMs),
        deadline_ms = tonumber(deadlineMs),
        layout = existingLayout,
        already_started = true
    })
end

if status == 'COMPLETED' then
    local submittedMs = redis.call('HGET', attKey, 'submitted_ms')
    return cjson.encode({
        status = 'COMPLETED',
        submitted_ms = tonumber(submittedMs)
    })
end

if status == 'ABSENT' then
    return cjson.encode({
        status = 'ABSENT'
    })
end

-- Calculate deadline: min(now + duration, end_at + overtime_grace)
local deadlineMs = nowMs + durationMs
local maxAllowedMs = endMs + overtimeGraceMs
if deadlineMs > maxAllowedMs then
    deadlineMs = maxAllowedMs
end

redis.call('HMSET', attKey,
    'status', 'IN_PROGRESS',
    'started_ms', tostring(nowMs),
    'deadline_ms', tostring(deadlineMs),
    'layout', layout
)

redis.call('ZADD', deadlinesKey, deadlineMs, aid)
redis.call('SADD', dirtyAttKey, aid)
redis.call('HINCRBY', qstatKey, 'started', 1)
redis.call('HINCRBY', qstatKey, 'in_progress', 1)

return cjson.encode({
    status = 'IN_PROGRESS',
    started_ms = nowMs,
    deadline_ms = deadlineMs,
    layout = layout,
    already_started = false
})
