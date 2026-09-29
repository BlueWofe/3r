import { test, expect } from '@playwright/test';
import { apiContext, createSession, json, login, mutate } from './helpers';

test('own/all schedule scopes are enforced by the server', async () => {
  const teacher = await apiContext();
  const member = await apiContext();
  const admin = await apiContext();
  try {
    await login(admin, '0900000001');
    const userRows = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const otherTeacher = userRows.data.find(user => user.phone === '0900000004');
    expect(otherTeacher, 'seeded fourth account should exist').toBeTruthy();
    const privateSession = await createSession(admin, otherTeacher!.id);
    await login(teacher, '0900000002');
    await login(member, '0900000003');

    const own = await teacher.get('/api/v1/sessions');
    expect(own.ok()).toBeTruthy();
    const ownBody = await own.json();
    expect(Array.isArray(ownBody.data)).toBeTruthy();
    expect(ownBody.data.some((session: { id: number }) => session.id === privateSession.id)).toBeFalsy();

    const forbiddenUsers = await member.get('/api/v1/users');
    expect(forbiddenUsers.status()).toBe(403);
    const forbiddenScheduleCreate = await mutate(member, 'post', '/api/v1/sessions', {});
    expect(forbiddenScheduleCreate.status()).toBe(403);
  } finally {
    await teacher.dispose();
    await member.dispose();
    await admin.dispose();
  }
});

test('system admin can read all schedules and role permissions', async () => {
  const admin = await apiContext();
  try {
    await login(admin, '0900000001');
    const [sessions, roles] = await Promise.all([
      admin.get('/api/v1/sessions'),
      admin.get('/api/v1/roles'),
    ]);
    expect(sessions.ok()).toBeTruthy();
    expect(roles.ok()).toBeTruthy();
    expect((await roles.json()).data.length).toBeGreaterThan(0);
  } finally {
    await admin.dispose();
  }
});
