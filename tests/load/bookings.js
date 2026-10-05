import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const succeeded = new Counter('booking_success');
const conflicted = new Counter('booking_conflict');
const unexpected = new Counter('booking_unexpected');

export const options = {
  scenarios: {
    competing_bookings: {
      executor: 'shared-iterations',
      vus: 20,
      iterations: 20,
      maxDuration: '30s',
    },
  },
  thresholds: {
    booking_success: ['count==5'],
    booking_conflict: ['count==15'],
    booking_unexpected: ['count==0'],
  },
};

export default function () {
  const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
  const response = http.post(`${baseUrl}/api/v1/bookings`, JSON.stringify({
    hotel_id: Number(__ENV.HOTEL_ID),
    room_type_id: Number(__ENV.ROOM_TYPE_ID),
    check_in_date: __ENV.CHECK_IN_DATE,
    check_out_date: __ENV.CHECK_OUT_DATE,
    rooms_count: 1,
    customer_name: `Load Test ${__VU}-${__ITER}`,
    customer_contact: `load-${__VU}-${__ITER}@example.test`,
  }), {
    headers: {
      Authorization: `Bearer ${__ENV.API_TOKEN}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': `k6-${__VU}-${__ITER}-${Date.now()}`,
      Accept: 'application/json',
    },
  });

  if (response.status === 201) {
    succeeded.add(1);
  } else if (response.status === 409) {
    conflicted.add(1);
  } else {
    unexpected.add(1);
  }

  check(response, {
    'request returns booking or availability conflict': (r) => r.status === 201 || r.status === 409,
  });
}
