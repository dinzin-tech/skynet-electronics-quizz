import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        concurrent_assessment: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 500 },
                { duration: '60s', target: 2000 },
                { duration: '5m', target: 2000 },
                { duration: '30s', target: 0 },
            ],
            gracefulRampDown: '15s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.001'], // Zero unhandled failures (< 0.1%)
        'http_req_duration{endpoint:save}': ['p(95)<150', 'p(99)<300'],
        'http_req_duration{endpoint:start}': ['p(95)<300', 'p(99)<500'],
        'http_req_duration{endpoint:submit}': ['p(95)<300', 'p(99)<500'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1';
const QUIZ_CODE = __ENV.QUIZ_CODE || 'SEEDED_QUIZ_01';

export default function () {
    const vuId = __VU;
    const employeeCode = `EMP_${String(vuId).padStart(4, '0')}`;
    const password = 'QuizPassword2026!';

    // 1. Authenticate
    const loginRes = http.post(`${BASE_URL}/api/auth/login`, JSON.stringify({
        identifier: employeeCode,
        password: password,
    }), { headers: { 'Content-Type': 'application/json' } });

    if (loginRes.status !== 200) {
        return;
    }

    const token = JSON.parse(loginRes.body).token;
    const authHeaders = {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
    };

    // 2. Start Quiz (Atomic Lua Execution)
    const startRes = http.post(`${BASE_URL}/api/quiz/${QUIZ_CODE}/start`, '{}', {
        headers: authHeaders,
        tags: { endpoint: 'start' },
    });

    const startOk = check(startRes, {
        'start returned 200': (r) => r.status === 200,
        'has attempt_id': (r) => JSON.parse(r.body || '{}').attempt_id !== undefined,
        'has layout': (r) => JSON.parse(r.body || '{}').layout !== undefined,
    });

    if (!startOk) {
        return;
    }

    const startData = JSON.parse(startRes.body);
    const attemptId = startData.attempt_id;
    const layout = startData.layout;
    const questionIds = Object.keys(layout);

    // 3. Mixed Attempt: Answer questions sequentially with saves every 15-30s
    let seq = 1;
    const questionsToAnswer = Math.min(questionIds.length, 10); // Answer sample during test run

    for (let i = 0; i < questionsToAnswer; i++) {
        const qid = questionIds[i];
        const optionsList = layout[qid] || [1, 2, 3, 4];
        const selectedOption = optionsList[0];

        sleep(1 + Math.random() * 2);

        // Hot Save (PUT /api/attempts/{aid}/answers)
        const savePayload = JSON.stringify({
            question_id: parseInt(qid, 10),
            option_id: selectedOption,
            seq: seq++,
        });

        const saveRes = http.put(`${BASE_URL}/api/attempts/${attemptId}/answers`, savePayload, {
            headers: authHeaders,
            tags: { endpoint: 'save' },
        });

        check(saveRes, {
            'save returned 200': (r) => r.status === 200,
            'max_seq is acknowledged': (r) => {
                const b = JSON.parse(r.body || '{}');
                return b.max_seq !== undefined;
            },
        });
    }

    // 4. Submit Attempt (Atomic SUBMIT Lua with idempotency test)
    const submitRes = http.post(`${BASE_URL}/api/attempts/${attemptId}/submit`, '{}', {
        headers: authHeaders,
        tags: { endpoint: 'submit' },
    });

    check(submitRes, {
        'submit returned 200': (r) => r.status === 200,
        'status is COMPLETED': (r) => JSON.parse(r.body || '{}').status === 'COMPLETED',
    });

    // Verify double-click idempotency
    const doubleSubmitRes = http.post(`${BASE_URL}/api/attempts/${attemptId}/submit`, '{}', {
        headers: authHeaders,
        tags: { endpoint: 'submit' },
    });

    check(doubleSubmitRes, {
        'double-submit returned 200 (idempotent)': (r) => r.status === 200,
        'status remains COMPLETED': (r) => JSON.parse(r.body || '{}').status === 'COMPLETED',
    });
}
