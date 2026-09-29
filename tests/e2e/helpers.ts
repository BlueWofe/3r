import { expect, request, type APIRequestContext, type APIResponse } from '@playwright/test';

export const baseURL = process.env.BASE_URL ?? 'http://localhost:3180';
export const demoPassword = process.env.DEMO_PASSWORD;

if (!demoPassword) throw new Error('Set DEMO_PASSWORD to the synthetic demo seed password.');

export async function apiContext(): Promise<APIRequestContext> {
  return request.newContext({ baseURL, extraHTTPHeaders: { Accept: 'application/json' } });
}

export async function login(api: APIRequestContext, phone: string) {
  const csrf = await api.get('/api/v1/auth/csrf');
  expect(csrf.ok(), `CSRF endpoint returned ${csrf.status()}`).toBeTruthy();
  const { csrf_token } = await csrf.json();
  const response = await api.post('/api/v1/auth/login', {
    data: { phone, password: demoPassword },
    headers: { 'X-CSRF-TOKEN': csrf_token },
  });
  expect(response.ok(), `Login for ${phone} returned ${response.status()}`).toBeTruthy();
  return response;
}

export async function csrfToken(api: APIRequestContext) {
  const response = await api.get('/api/v1/auth/csrf');
  expect(response.ok()).toBeTruthy();
  return (await response.json()).csrf_token as string;
}

export async function mutate(api: APIRequestContext, method: 'post' | 'put' | 'delete', path: string, data?: unknown, extraHeaders: Record<string, string> = {}) {
  const token = await csrfToken(api);
  return api[method](path, { data, headers: { 'X-CSRF-TOKEN': token, ...extraHeaders } });
}

export async function json<T>(response: APIResponse): Promise<T> {
  expect(response.ok(), `${response.url()} returned ${response.status()}: ${await response.text()}`).toBeTruthy();
  return response.json() as Promise<T>;
}

export const unique = (prefix: string) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

export function futureDate(daysAhead = 14) {
  const date = new Date();
  // Spread parallel test sessions across future dates to avoid teacher conflicts.
  date.setDate(date.getDate() + daysAhead + Math.floor(Math.random() * 7000));
  return date.toISOString().slice(0, 10);
}

export interface TestAssignment { id: number; teacher_id: number; status: 'assigned' | 'leave' | 'replaced'; attendance: unknown }
export interface TestSession { id: number; version: number; assignments: TestAssignment[]; status: 'scheduled' | 'cancelled' }

export async function createSession(api: APIRequestContext, teacherId: number, title = unique('E2E'), teacherIds: number[] = [teacherId]) {
  // Persistent dev/UAT datasets retain earlier test sessions. Choose a date
  // with no existing classes so later substitute teachers are also available.
  let serviceDate = futureDate();
  for (let attempt = 0; attempt < 20; attempt++) {
    const existing = await json<{ data: unknown[] }>(await api.get(`/api/v1/sessions?from=${serviceDate}&to=${serviceDate}`));
    if (!existing.data.length) break;
    serviceDate = futureDate();
  }
  return json<TestSession>(
    await mutate(api, 'post', '/api/v1/sessions', {
      title, prison: '示範場域', location: 'E2E 驗收用', participant_count: 0,
      service_date: serviceDate, start_time: '10:00', end_time: '11:00', teacher_ids: teacherIds, repeat_weeks: 1,
    }),
  );
}
