-- flip_absent.lua
-- Atomically flips unstarted attempts for a closed quiz to ABSENT.
-- KEYS[1] = qa:{quizId} (hash: employee_id -> attempt_id)
-- KEYS[2] = dirty_att (set: aid)
-- KEYS[3] = qstat:{quizId} (hash)
-- ARGV[1] = quizId (string)

local qaKey = KEYS[1]
local dirtyAttKey = KEYS[2]
local qstatKey = KEYS[3]

local aids = redis.call('HVALS', qaKey)
local flippedCount = 0

for _, aid in ipairs(aids) do
    local attKey = 'att:' .. aid
    local status = redis.call('HGET', attKey, 'status')
    if status == 'NOT_STARTED' then
        redis.call('HSET', attKey, 'status', 'ABSENT')
        redis.call('SADD', dirtyAttKey, aid)
        flippedCount = flippedCount + 1
    end
end

if flippedCount > 0 and redis.call('EXISTS', qstatKey) == 1 then
    redis.call('HINCRBY', qstatKey, 'absent', flippedCount)
end

return cjson.encode({
    ok = true,
    flipped_count = flippedCount
})
