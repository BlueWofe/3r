import { expect, test } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

type CaseRecord = {
  id: string;
  service_date: string;
  type: string;
  summary: string;
  follow_up: string;
  author_id: number;
  author_name?: string;
};
type CaseFile = { id: number; code: string; name: string; records: CaseRecord[] };

test('service records survive independent case detail and list reads', async () => {
  const admin = await apiContext();
  let caseId: number | undefined;
  try {
    await login(admin, '0900000001');
    const created = await json<CaseFile>(await mutate(admin, 'post', '/api/v1/cases', {
      code: unique('DURABLE-CASE'), name: unique('持久化驗收個案'), status: '在案',
      contact: '僅供自動驗收',
    }));
    caseId = created.id;
    expect(created.records).toEqual([]);

    const record = {
      service_date: new Date().toISOString().slice(0, 10),
      type: 'E2E 持久化關懷',
      summary: unique('再次載入可見服務摘要'),
      follow_up: 'E2E 持久化後續追蹤',
    };
    const saved = await json<CaseFile>(await mutate(admin, 'post', `/api/v1/cases/${caseId}/records`, record));
    expect(saved.records).toHaveLength(1);
    expect(saved.records[0]).toMatchObject(record);
    expect(saved.records[0].id).toBeTruthy();
    expect(saved.records[0].author_id).toBeTruthy();

    // Use independent reads after the mutation; this catches responses that look correct while data was not saved.
    const detail = await json<CaseFile>(await admin.get(`/api/v1/cases/${caseId}`));
    expect(detail.records).toEqual(saved.records);
    const list = await json<{ data: CaseFile[] }>(await admin.get('/api/v1/cases'));
    expect(list.data.find(row => row.id === caseId)?.records).toEqual(saved.records);
  } finally {
    if (caseId) {
      const removed = await mutate(admin, 'delete', `/api/v1/cases/${caseId}`);
      expect([200, 204, 404]).toContain(removed.status());
    }
    await admin.dispose();
  }
});

test('meeting list and detail expose readable role names while retaining role IDs for editing', async () => {
  const admin = await apiContext();
  let meetingId: number | undefined;
  try {
    await login(admin, '0900000001');
    const roles = await json<{ data: { id: number; name: string }[] }>(await admin.get('/api/v1/role-options'));
    const role = roles.data[0];
    expect(role, 'at least one active role exists').toBeTruthy();
    const meeting = await json<{ id: number; role_ids: number[]; role_names: string[] }>(await mutate(admin, 'post', '/api/v1/meetings', {
      title: unique('角色名稱會議'), meeting_date: new Date().toISOString().slice(0, 10),
      agenda: '合成驗收', minutes: '', decisions: '', role_ids: [role!.id], file_ids: [], group_ids: [],
    }));
    meetingId = meeting.id;
    expect(meeting.role_ids).toContain(role!.id);
    expect(meeting.role_names).toContain(role!.name);

    const detail = await json<{ id: number; role_ids: number[]; role_names: string[] }>(await admin.get(`/api/v1/meetings/${meeting.id}`));
    expect(detail.role_ids).toContain(role!.id);
    expect(detail.role_names).toEqual([role!.name]);
    const list = await json<{ data: typeof detail[] }>(await admin.get('/api/v1/meetings'));
    expect(list.data.find(row => row.id === meeting.id)?.role_names).toEqual([role!.name]);
  } finally {
    if (meetingId) {
      const removed = await mutate(admin, 'delete', `/api/v1/meetings/${meetingId}`);
      expect([200, 204, 404]).toContain(removed.status());
    }
    await admin.dispose();
  }
});
