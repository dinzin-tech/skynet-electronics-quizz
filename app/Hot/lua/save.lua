-- save.lua
-- Atomically saves answers with monotonic seq last-write-wins resolution.
-- KEYS[1] = att:{aid}
-- KEYS[2] = ans:{aid} (hash: question_id -> "option_id|seq|ts_ms")
-- KEYS[3] = dirty (set: "aid:question_id")
-- ARGV[1] = now_ms
-- ARGV[2] = grace_ms
-- ARGV[3] = items_json (JSON string: [{"q": qid, "o": oid, "seq": seq, "ts": ts}, ...])
-- ARGV[4] = aid (string)

local attKey = KEYS[1]
local ansKey = KEYS[2]
local dirtyKey = KEYS[3]

local nowMs = tonumber(ARGV[1])
local graceMs = tonumber(ARGV[2])
local itemsJson = ARGV[3]
local aid = ARGV[4]

local status = redis.call('HGET', attKey, 'status')
if not status then
    return cjson.encode({error = 'attempt_not_found'})
end

if status ~= 'IN_PROGRESS' then
    return cjson.encode({error = 'not_in_progress', status = status})
end

local deadlineMs = tonumber(redis.call('HGET', attKey, 'deadline_ms') or '0')
if deadlineMs > 0 and nowMs > (deadlineMs + graceMs) then
    return cjson.encode({error = 'deadline_passed', deadline_ms = deadlineMs, now_ms = nowMs})
end

local items = cjson.decode(itemsJson)
local currentMaxSeq = tonumber(redis.call('HGET', attKey, 'max_seq') or '0')
local updatedCount = 0

for _, item in ipairs(items) do
    local qid = tostring(item.q)
    local oid = tostring(item.o)
    local seq = tonumber(item.seq)
    local ts = tonumber(item.ts or nowMs)

    local existingVal = redis.call('HGET', ansKey, qid)
    local apply = true

    if existingVal then
        -- Parse stored seq: "option_id|seq|ts_ms"
        local pipeIdx = string.find(existingVal, '|', 1, true)
        if pipeIdx then
            local nextPipeIdx = string.find(existingVal, '|', pipeIdx + 1, true)
            local storedSeq = 0
            if nextPipeIdx then
                storedSeq = tonumber(string.sub(existingVal, pipeIdx + 1, nextPipeIdx - 1)) or 0
            else
                storedSeq = tonumber(string.sub(existingVal, pipeIdx + 1)) or 0
            end
            if seq <= storedSeq then
                apply = false
            end
        end
    end

    if apply then
        local record = oid .. '|' .. tostring(seq) .. '|' .. tostring(ts)
        redis.call('HSET', ansKey, qid, record)
        redis.call('SADD', dirtyKey, aid .. ':' .. qid)
        updatedCount = updatedCount + 1
    end

    if seq > currentMaxSeq then
        currentMaxSeq = seq
    end
end

redis.call('HMSET', attKey,
    'max_seq', tostring(currentMaxSeq),
    'last_saved_ms', tostring(nowMs)
)

return cjson.encode({
    ok = true,
    updated_count = updatedCount,
    max_seq = currentMaxSeq,
    server_now_ms = nowMs
})
