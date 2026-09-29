import { expect, test } from '@playwright/test';
import { apiContext, futureDate, json, login, mutate, unique } from './helpers';

interface Group {
  id: number;
  name: string;
  description: string;
  active: boolean;
  version: number;
  member_ids: number[];
  members: { id: number; name: string; active: boolean }[];
  member_count: number;
}

test('group managers can maintain unique groups while members receive only safe options', async () => {
  const admin = await apiContext();
  const member = await apiContext();
  let group: Group | undefined;
  let inactiveUserId: number | undefined;
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const ordinaryMember = users.data.find(user => user.phone === '0900000003');
    expect(ordinaryMember, 'synthetic member exists').toBeTruthy();

    group = await json<Group>(await mutate(admin, 'post', '/api/v1/groups', {
      name: unique('E2E 小組'),
      description: '分組權限測試資料',
      active: true,
      member_ids: [ordinaryMember!.id, ordinaryMember!.id],
    }));
    expect(group.version).toBe(1);
    expect(group.member_ids).toEqual([ordinaryMember!.id]);
    expect(group.member_count).toBe(1);
    expect(group.members[0]).toMatchObject({ id: ordinaryMember!.id, active: true });
    expect(group.members[0]).not.toHaveProperty('phone');

    const members = await json<{ data: { id: number; name: string; active: boolean; phone?: string }[] }>(
      await admin.get('/api/v1/groups/member-options'),
    );
    expect(members.data.find(user => user.id === ordinaryMember!.id)).toMatchObject({ id: ordinaryMember!.id, active: true });
    expect(members.data.find(user => user.id === ordinaryMember!.id)).not.toHaveProperty('phone');

    const updated = await json<Group>(await mutate(admin, 'put', `/api/v1/groups/${group.id}`, {
      name: group.name,
      description: '保留第二版說明',
      active: true,
      member_ids: [ordinaryMember!.id],
      version: group.version,
    }));
    expect(updated.version).toBe(group.version + 1);
    expect((await mutate(admin, 'put', `/api/v1/groups/${group.id}`, {
      name: group.name,
      description: '不應覆蓋新版本',
      active: true,
      member_ids: [ordinaryMember!.id],
      version: group.version,
    })).status()).toBe(409);
    group = updated;

    const inactive = await json<{ id: number }>(await mutate(admin, 'post', '/api/v1/users', {
      name: unique('停用合成帳號'),
      phone: uniquePhone(),
      password: unique('Synthetic-Password-'),
      active: false,
      role_ids: [],
    }));
    inactiveUserId = inactive.id;
    const invalidMembership = await mutate(admin, 'put', `/api/v1/groups/${group.id}`, {
      name: group.name,
      description: group.description,
      active: true,
      member_ids: [ordinaryMember!.id, inactive.id],
      version: group.version,
    });
    expect(invalidMembership.status()).toBe(422);

    await login(member, '0900000003');
    const ownOptions = await json<{ data: { id: number; name: string; [key: string]: unknown }[] }>(
      await member.get('/api/v1/groups/options'),
    );
    expect(ownOptions.data).toEqual([{ id: group.id, name: group.name }]);
    expect(ownOptions.data[0]).not.toHaveProperty('members');
    expect((await member.get('/api/v1/groups')).status()).toBe(403);
    expect((await member.get('/api/v1/groups/member-options')).status()).toBe(403);
    expect((await mutate(member, 'post', '/api/v1/groups', {
      name: unique('不可管理小組'), active: true, member_ids: [],
    })).status()).toBe(403);
  } finally {
    if (group) {
      const fresh = await admin.get(`/api/v1/groups/${group.id}`);
      if (fresh.ok()) {
        const latest = await fresh.json() as Group;
        await mutate(admin, 'put', `/api/v1/groups/${group.id}`, {
          name: latest.name,
          description: latest.description,
          active: false,
          member_ids: [],
          version: latest.version,
        });
      }
    }
    if (inactiveUserId) {
      // Keep the synthetic account disabled even if a preceding assertion failed.
      const response = await admin.get('/api/v1/users');
      if (response.ok()) {
        const all = await response.json() as { data: { id: number; name: string; active: boolean }[] };
        const user = all.data.find(row => row.id === inactiveUserId);
        if (user?.active) await mutate(admin, 'put', `/api/v1/users/${user.id}`, { name: user.name, active: false, role_ids: [] });
      }
    }
    await Promise.all([admin.dispose(), member.dispose()]);
  }
});

function uniquePhone() {
  return `09${String(Math.floor(Math.random() * 100_000_000)).padStart(8, '0')}`;
}

test('private resource downloads follow their own group scope instead of a linked meeting scope', async () => {
  const admin = await apiContext();
  const teacherA = await apiContext();
  const teacherB = await apiContext();
  let groups: Group[] = [];
  let resourceId: number | undefined;
  let meetingId: number | undefined;
  let fileId: number | undefined;
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const teacherAId = users.data.find(user => user.phone === '0900000002')!.id;
    const teacherBId = users.data.find(user => user.phone === '0900000004')!.id;
    for (const [name, memberId] of [['會議對象', teacherAId], ['資源對象', teacherBId]] as const) {
      groups.push(await json<Group>(await mutate(admin, 'post', '/api/v1/groups', {
        name: unique(`附件範圍${name}`), description: '合成附件存取驗收', active: true, member_ids: [memberId],
      })));
    }

    const token = await (await admin.get('/api/v1/auth/csrf')).json().then(body => body.csrf_token as string);
    const upload = await admin.post('/api/v1/resources', {
      headers: { 'X-CSRF-TOKEN': token },
      multipart: {
        title: unique('群組私人附件'), category: 'E2E 私人附件',
        'group_ids[0]': String(groups[1].id),
        file: { name: 'group-scope-e2e.txt', mimeType: 'text/plain', buffer: Buffer.from('synthetic private resource') },
      },
    });
    const resource = await json<{ id: number; file_id: number; title: string }>(upload);
    resourceId = resource.id;
    fileId = resource.file_id;

    const meeting = await json<{ id: number }>(await mutate(admin, 'post', '/api/v1/meetings', {
      title: unique('寬範圍會議連結'), meeting_date: futureDate(), agenda: 'E2E', minutes: '', decisions: '',
      role_ids: [], group_ids: [groups[0].id], file_ids: [fileId],
    }));
    meetingId = meeting.id;
    await login(teacherA, '0900000002');
    await login(teacherB, '0900000004');

    const visibleMeeting = await json<{ data: { id: number; file_ids?: number[] }[] }>(await teacherA.get(`/api/v1/meetings?group_id=${groups[0].id}`));
    expect(visibleMeeting.data.some(row => row.id === meetingId)).toBeTruthy();
    // A meeting may reference the file but may not widen the resource's separate access list.
    expect((await teacherA.get(`/api/v1/files/${fileId}/download`)).status()).toBe(403);
    expect((await teacherB.get(`/api/v1/files/${fileId}/download`)).status()).toBe(200);
    const visibleResource = await json<{ data: { id: number }[] }>(await teacherB.get(`/api/v1/resources?group_id=${groups[1].id}`));
    expect(visibleResource.data.some(row => row.id === resourceId)).toBeTruthy();
    expect((await teacherA.get(`/api/v1/resources?group_id=${groups[1].id}`)).status()).toBe(200);

    await mutate(admin, 'put', `/api/v1/resources/${resourceId}`, { title: '改到會議小組', group_ids: [groups[0].id] });
    // Omitting group_ids on a metadata-only edit preserves the current audience.
    await mutate(admin, 'put', `/api/v1/resources/${resourceId}`, { category: 'E2E 保留範圍' });
    expect((await teacherA.get(`/api/v1/files/${fileId}/download`)).status()).toBe(200);
    expect((await teacherB.get(`/api/v1/files/${fileId}/download`)).status()).toBe(403);
  } finally {
    if (meetingId) await mutate(admin, 'delete', `/api/v1/meetings/${meetingId}`);
    if (resourceId) await mutate(admin, 'delete', `/api/v1/resources/${resourceId}`);
    for (const group of groups) {
      const response = await admin.get(`/api/v1/groups/${group.id}`);
      if (response.ok()) {
        const current = await response.json() as Group;
        await mutate(admin, 'put', `/api/v1/groups/${group.id}`, {
          name: current.name, description: current.description, active: false, member_ids: [], version: current.version,
        });
      }
    }
    await Promise.all([admin.dispose(), teacherA.dispose(), teacherB.dispose()]);
  }
});
