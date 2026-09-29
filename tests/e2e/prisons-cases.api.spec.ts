import { expect, test } from '@playwright/test';
import { apiContext, futureDate, json, login, mutate, unique } from './helpers';

test('prison rename and deactivation preserve case/session history but block new associations', async () => {
  const api = await apiContext();
  try {
    await login(api, '0900000001');
    const { user: admin } = await json<{ user: { id: number; name: string } }>(await api.get('/api/v1/auth/me'));
    const { data: users } = await json<{ data: { id: number; phone: string; name: string }[] }>(await api.get('/api/v1/users'));
    const assignedUser = users.find(user => user.phone === '0900000002');
    expect(assignedUser, 'synthetic volunteer account exists').toBeTruthy();

    const originalName = unique('合成監所');
    const renamedName = `${originalName}-更新`;
    const prison = await json<{
      id: number;
      name: string;
      address: string | null;
      active: boolean;
      version: number;
    }>(await mutate(api, 'post', '/api/v1/prisons', { name: originalName, address: '合成地址', active: true }));
    expect(prison).toMatchObject({ name: originalName, active: true, version: 1 });
    const duplicate = await mutate(api, 'post', '/api/v1/prisons', { name: originalName, active: true });
    expect(duplicate.status()).toBe(422);

    const { data: options } = await json<{ data: { id: number; name: string; active: boolean }[] }>(await api.get('/api/v1/prisons/options'));
    expect(options).toContainEqual(expect.objectContaining({ id: prison.id, name: originalName, active: true }));

    const caseCode = unique('CASE');
    const caseName = unique('合成個案');
    const createdCase = await json<{
      id: number;
      code: string;
      name: string;
      prison_id: number;
      prison: string;
      assigned_user_id: number;
      assigned_user_name: string;
      records: unknown[];
    }>(await mutate(api, 'post', '/api/v1/cases', {
      code: caseCode,
      name: caseName,
      status: '在案',
      prison_id: prison.id,
      assigned_user_id: assignedUser!.id,
      contact: '僅供測試',
    }));
    expect(createdCase).toMatchObject({
      code: caseCode,
      name: caseName,
      prison_id: prison.id,
      prison: originalName,
      assigned_user_id: assignedUser!.id,
      assigned_user_name: assignedUser!.name,
      records: [],
    });
    const { data: cases } = await json<{ data: typeof createdCase[] }>(await api.get('/api/v1/cases'));
    expect(cases.some(item => item.id === createdCase.id && item.assigned_user_name === assignedUser!.name)).toBe(true);

    const serviceDate = futureDate(5);
    const session = await json<{
      id: number;
      service_date: string;
      prison_id: number;
      prison: string;
    }>(await mutate(api, 'post', '/api/v1/sessions', {
      title: unique('監所關聯課程'),
      prison_id: prison.id,
      location: '合成教室',
      participant_count: 0,
      service_date: serviceDate,
      start_time: '09:00',
      end_time: '10:00',
      teacher_ids: [assignedUser!.id],
      override_conflict: true,
      reason: '監所關聯 API 驗收',
    }));
    expect(session).toMatchObject({ prison_id: prison.id, prison: originalName, service_date: serviceDate });

    const recordSummary = unique('關懷服務摘要');
    const updatedCase = await json<{
      id: number;
      records: { service_date: string; type: string; summary: string; author_name: string; author_id: number }[];
    }>(
      await mutate(api, 'post', `/api/v1/cases/${createdCase.id}/records`, {
        service_date: serviceDate,
        type: '電話關懷',
        summary: recordSummary,
        follow_up: '下次追蹤',
      }),
    );
    expect(updatedCase.records).toEqual(expect.arrayContaining([
      expect.objectContaining({ service_date: serviceDate, type: '電話關懷', summary: recordSummary, author_name: admin.name }),
    ]));
    expect(updatedCase.records[0]?.author_id).toBe(admin.id);

    const renamed = await json<typeof prison>(await mutate(api, 'put', `/api/v1/prisons/${prison.id}`, {
      name: renamedName,
      address: '更新後合成地址',
      active: true,
      version: prison.version,
    }));
    expect(renamed).toMatchObject({ id: prison.id, name: renamedName, active: true, version: prison.version + 1 });
    const renamedCase = await json<typeof createdCase & { records: unknown[] }>(await api.get(`/api/v1/cases/${createdCase.id}`));
    const renamedSession = await json<typeof session>(await api.get(`/api/v1/sessions/${session.id}`));
    expect(renamedCase).toMatchObject({ prison_id: prison.id, prison: renamedName });
    expect(renamedSession).toMatchObject({ prison_id: prison.id, prison: renamedName });

    const stale = await mutate(api, 'put', `/api/v1/prisons/${prison.id}`, {
      name: unique('過期版本'),
      active: true,
      version: prison.version,
    });
    expect(stale.status()).toBe(409);

    const disabled = await json<typeof prison>(await mutate(api, 'put', `/api/v1/prisons/${prison.id}`, {
      name: renamedName,
      address: '更新後合成地址',
      active: false,
      version: renamed.version,
    }));
    expect(disabled).toMatchObject({ id: prison.id, name: renamedName, active: false, version: renamed.version + 1 });
    const { data: inactiveOptions } = await json<{ data: { id: number; name: string; active: boolean }[] }>(await api.get('/api/v1/prisons/options'));
    expect(inactiveOptions).toContainEqual(expect.objectContaining({ id: prison.id, name: renamedName, active: false }));
    const archivedCase = await json<typeof createdCase & { records: unknown[] }>(await api.get(`/api/v1/cases/${createdCase.id}`));
    const archivedSession = await json<typeof session>(await api.get(`/api/v1/sessions/${session.id}`));
    expect(archivedCase).toMatchObject({ prison_id: prison.id, prison: renamedName });
    expect(archivedSession).toMatchObject({ prison_id: prison.id, prison: renamedName });
    const inactiveCase = await mutate(api, 'post', '/api/v1/cases', {
      code: unique('CASE'), name: unique('不可選個案'), status: '在案', prison_id: prison.id,
    });
    expect(inactiveCase.status()).toBe(422);
    const inactiveSession = await mutate(api, 'post', '/api/v1/sessions', {
      title: unique('不可選課程'), prison_id: prison.id, location: '合成教室', participant_count: 0,
      service_date: futureDate(6), start_time: '11:00', end_time: '12:00', teacher_ids: [assignedUser!.id],
    });
    expect(inactiveSession.status()).toBe(422);

    const legacyName = unique('舊資料監所');
    const legacySession = await json<{ prison_id: number; prison: string }>(await mutate(api, 'post', '/api/v1/sessions', {
      title: unique('舊欄位相容課程'), prison: legacyName, location: '合成教室', participant_count: 0,
      service_date: futureDate(7), start_time: '13:00', end_time: '14:00', teacher_ids: [assignedUser!.id],
    }));
    expect(legacySession.prison_id).toBeTruthy();
    expect(legacySession.prison).toBe(legacyName);
  } finally {
    await api.dispose();
  }
});

test('prison management is limited to prison managers while scheduling staff can use options', async () => {
  const api = await apiContext();
  try {
    await login(api, '0900000002');
    expect((await api.get('/api/v1/prisons')).status()).toBe(403);
    const options = await api.get('/api/v1/prisons/options');
    expect(options.status()).toBe(200);
    const payload = await options.json();
    expect(Array.isArray(payload.data)).toBe(true);
  } finally {
    await api.dispose();
  }
});
