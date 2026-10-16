import { test, expect } from '@playwright/test';
import { apiContext, createSession, json, login, mutate } from './helpers';

test('teacher leave, invitation acceptance, then admin replacement preserve assignment history', async () => {
  const admin = await apiContext();
  const leavingTeacher = await apiContext();
  const invitedTeacher = await apiContext();
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const adminTeacher = users.data.find(user => user.phone === '0900000001');
    const leaving = users.data.find(user => user.phone === '0900000002');
    const invited = users.data.find(user => user.phone === '0900000004');
    expect(adminTeacher, 'seeded admin/teacher should exist').toBeTruthy();
    expect(leaving, 'seeded second teacher should exist').toBeTruthy();
    expect(invited, 'seeded fourth teacher should exist').toBeTruthy();
    const session = await createSession(admin, leaving!.id);
    const assignment = session.assignments[0];
    expect(assignment).toBeTruthy();

    await login(leavingTeacher, '0900000002');
    const leave = await mutate(leavingTeacher, 'post', `/api/v1/assignments/${assignment.id}/leave`, { version: session.version, reason: 'E2E 測試請假' });
    expect(leave.ok()).toBeTruthy();

    const current = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    const invite = await mutate(admin, 'post', `/api/v1/assignments/${assignment.id}/invite`, { version: current.version, teacher_id: invited!.id, reason: 'E2E 補位邀請' });
    expect(invite.ok()).toBeTruthy();

    await login(invitedTeacher, '0900000004');
    const invitations = await json<{ data: { id: number; session_id: number }[] }>(await invitedTeacher.get('/api/v1/invitations'));
    const invitation = invitations.data.find(item => item.session_id === session.id);
    expect(invitation, 'invitation should be visible to its recipient').toBeTruthy();
    const accepted = await mutate(invitedTeacher, 'post', `/api/v1/invitations/${invitation!.id}/respond`, { action: 'accept' });
    expect(accepted.ok()).toBeTruthy();

    const afterAccept = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    expect(afterAccept.assignments.some(a => a.teacher_id === invited!.id && a.status === 'assigned')).toBeTruthy();
    const acceptedAssignment = afterAccept.assignments.find(a => a.teacher_id === invited!.id && a.status === 'assigned');
    const replacement = await mutate(admin, 'post', `/api/v1/assignments/${acceptedAssignment!.id}/replace`, {
      version: afterAccept.version, teacher_id: adminTeacher!.id, reason: 'E2E 管理員再次調整補位',
    });
    expect(replacement.ok()).toBeTruthy();
    const final = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    expect(final.assignments.some(a => a.teacher_id === adminTeacher!.id && a.status === 'assigned')).toBeTruthy();
    expect(final.assignments.some(a => a.teacher_id === invited!.id && a.status !== 'assigned')).toBeTruthy();
  } finally {
    await Promise.all([admin.dispose(), leavingTeacher.dispose(), invitedTeacher.dispose()]);
  }
});

test('cancelled sessions reject attendance and stale schedule versions conflict', async () => {
  const admin = await apiContext();
  try {
    await login(admin, '0900000001');
    const teachers = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const teacher = teachers.data.find(user => user.phone === '0900000002');
    expect(teacher).toBeTruthy();
    const session = await createSession(admin, teacher!.id);
    const assignment = session.assignments[0];
    const cancelled = await mutate(admin, 'put', `/api/v1/sessions/${session.id}`, { version: session.version, status: 'cancelled', reason: 'E2E 取消' });
    expect(cancelled.ok()).toBeTruthy();
    const attendance = await mutate(admin, 'post', `/api/v1/assignments/${assignment.id}/attendance`, { reason: '取消課程不得點名' });
    expect([409, 422]).toContain(attendance.status());

    const fresh = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    const first = await mutate(admin, 'put', `/api/v1/sessions/${session.id}`, { version: fresh.version, reason: 'E2E 版本更新', title: '更新標題' });
    expect(first.ok()).toBeTruthy();
    const stale = await mutate(admin, 'put', `/api/v1/sessions/${session.id}`, { version: fresh.version, reason: 'E2E 舊版本', title: '舊版本覆寫' });
    expect(stale.status()).toBe(409);
  } finally {
    await admin.dispose();
  }
});

test('shared session teachers cannot edit schedule or act on a colleague assignment; admin can acknowledge changes', async () => {
  const admin = await apiContext();
  const teacher = await apiContext();
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const ownTeacher = users.data.find(user => user.phone === '0900000002');
    const otherTeacher = users.data.find(user => user.phone === '0900000004');
    expect(ownTeacher).toBeTruthy();
    expect(otherTeacher).toBeTruthy();
    const session = await createSession(admin, ownTeacher!.id, undefined, [ownTeacher!.id, otherTeacher!.id]);
    await login(teacher, '0900000002');
    const visible = await teacher.get(`/api/v1/sessions/${session.id}`);
    expect(visible.ok()).toBeTruthy();
    const colleagueAssignment = session.assignments.find(item => item.teacher_id === otherTeacher!.id)!;
    const colleagueLeave = await mutate(teacher, 'post', `/api/v1/assignments/${colleagueAssignment.id}/leave`, { version: session.version, reason: '不得替同工請假' });
    expect(colleagueLeave.status()).toBe(403);
    const scheduleEdit = await mutate(teacher, 'put', `/api/v1/sessions/${session.id}`, { version: session.version, reason: '教師不可改全體排程', title: '不應儲存' });
    expect(scheduleEdit.status()).toBe(403);

    const ownAssignment = session.assignments.find(item => item.teacher_id === ownTeacher!.id)!;
    const leave = await mutate(teacher, 'post', `/api/v1/assignments/${ownAssignment.id}/leave`, { version: session.version, reason: '本人請假' });
    expect(leave.ok()).toBeTruthy();
    const changes = await json<{ data: { id: number; session_id: number }[] }>(await admin.get('/api/v1/changes'));
    const change = changes.data.find(row => row.session_id === session.id);
    expect(change).toBeTruthy();
    const ack = await mutate(admin, 'post', `/api/v1/changes/${change!.id}/acknowledge`);
    expect(ack.ok()).toBeTruthy();
  } finally {
    await Promise.all([admin.dispose(), teacher.dispose()]);
  }
});

test('withdrawing leave cancels the pending invite and the superseded invite cannot be accepted', async () => {
  const admin = await apiContext();
  const teacher = await apiContext();
  const invitee = await apiContext();
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const leaving = users.data.find(user => user.phone === '0900000002');
    const invited = users.data.find(user => user.phone === '0900000004');
    expect(leaving).toBeTruthy();
    expect(invited).toBeTruthy();
    const session = await createSession(admin, leaving!.id);
    const assignment = session.assignments[0];

    await login(teacher, '0900000002');
    const leave = await mutate(teacher, 'post', `/api/v1/assignments/${assignment.id}/leave`, { version: session.version, reason: 'E2E 請假' });
    expect(leave.ok()).toBeTruthy();
    const afterLeave = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    const invite = await mutate(admin, 'post', `/api/v1/assignments/${assignment.id}/invite`, { version: afterLeave.version, teacher_id: invited!.id, reason: 'E2E 邀請' });
    expect(invite.ok()).toBeTruthy();
    await login(teacher, '0900000002');
    const afterInvite = await json<typeof session>(await admin.get(`/api/v1/sessions/${session.id}`));
    const withdraw = await mutate(teacher, 'post', `/api/v1/assignments/${assignment.id}/withdraw-leave`, { version: afterInvite.version, reason: 'E2E 撤回請假' });
    expect(withdraw.ok()).toBeTruthy();

    await login(invitee, '0900000004');
    const invitations = await json<{ data: { id: number; session_id: number; status: string }[] }>(await invitee.get('/api/v1/invitations'));
    const superseded = invitations.data.find(item => item.session_id === session.id);
    expect(superseded?.status).toBe('cancelled');
    const accept = await mutate(invitee, 'post', `/api/v1/invitations/${superseded!.id}/respond`, { action: 'accept' });
    expect(accept.status()).toBe(409);
  } finally {
    await Promise.all([admin.dispose(), teacher.dispose(), invitee.dispose()]);
  }
});
