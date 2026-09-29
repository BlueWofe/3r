import { test, expect } from '@playwright/test';
import { apiContext, createSession, demoPassword, json, login, mutate } from './helpers';

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

test('role permissions union while assigned and disappear immediately after role removal', async () => {
  const admin = await apiContext();
  const teacher = await apiContext();
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string; roles: { id: number }[] }[] }>(await admin.get('/api/v1/users'));
    const teacherUser = users.data.find(user => user.phone === '0900000002');
    const otherTeacher = users.data.find(user => user.phone === '0900000004');
    expect(teacherUser).toBeTruthy();
    expect(otherTeacher).toBeTruthy();
    const outsideSession = await createSession(admin, otherTeacher!.id);
    const slug = `e2e-schedule-all-${Date.now()}`;
    const role = await json<{ id: number }>(await mutate(admin, 'post', '/api/v1/roles', {
      name: 'E2E 全體排程檢視', slug, active: true, permissions: ['schedule.read.all'],
    }));
    const combinedRoles = [...teacherUser!.roles.map(item => item.id), role.id];
    const phone = '09' + String(Date.now()).slice(-8);
    const isolatedUser = await json<{id:number}>(await mutate(admin, 'post', '/api/v1/users', {
      name: 'E2E 隔離權限帳號', phone, password: demoPassword, active: true, role_ids: combinedRoles,
    }));

    await login(teacher, phone);
    const allAccess = await teacher.get(`/api/v1/sessions/${outsideSession.id}`);
    expect(allAccess.ok()).toBeTruthy();

    const revoked = await mutate(admin, 'put', `/api/v1/users/${isolatedUser.id}`, {
      name: 'E2E 隔離權限帳號', active: true, role_ids: teacherUser!.roles.map(item => item.id),
    });
    expect(revoked.ok()).toBeTruthy();
    const deniedAfterRevoke = await teacher.get(`/api/v1/sessions/${outsideSession.id}`);
    expect([401, 403]).toContain(deniedAfterRevoke.status());
    await login(teacher, phone);
    expect((await teacher.get(`/api/v1/sessions/${outsideSession.id}`)).status()).toBe(403);
  } finally {
    await Promise.all([admin.dispose(), teacher.dispose()]);
  }
});
