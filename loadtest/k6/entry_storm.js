import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        entry_storm: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 500 },
                { duration: '60s', target: 2000 },
                { duration: '30s', target: 2000 },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'], // < 1% errors
        http_req_duration: ['p(95)<400', 'p(99)<800'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1';
const QUIZ_CODE = __ENV.QUIZ_CODE || 'SEEDED_QUIZ_01';

export default function () {
    const vuId = __VU;
    const employeeCode = `EMP_${String(vuId).padStart(4, '0')}`;
    const password = 'QuizPassword2026!';

    // 1. Authenticate employee
    const loginPayload = JSON.stringify({
        identifier: employeeCode,
        password: password,
    });

    const loginRes = http.post(`${BASE_URL}/api/auth/login`, loginPayload, {
        headers: { 'Content-Type': 'application/json' },
    });

    const loginOk = check(loginRes, {
        'login status is 200': (r) => r.status === 200,
        'has auth token': (r) => {
            const body = JSON.parse(r.body || '{}');
            return body.token !== undefined;
        },
    });

    if (!loginOk) {
        sleep(1);
        return;
    }

    const token = JSON.parse(loginRes.body).token;
    const authHeaders = {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
    };

    // 2. Fetch server time
    const timeRes = http.get(`${BASE_URL}/api/time`, { headers: authHeaders });
    check(timeRes, {
        'time status is 200': (r) => r.status === 200,
    });

    // 3. Query quiz metadata (with ETag validation)
    const quizRes = http.get(`${BASE_URL}/api/quiz/${QUIZ_CODE}`, { headers: authHeaders });
    check(quizRes, {
        'quiz metadata is 200': (r) => r.status === 200,
        'quiz has duration': (r) => {
            const body = JSON.parse(r.body || '{}');
            return body.duration_minutes !== undefined;
        },
    });

    sleep(1 + Math.random() * 2);
}
