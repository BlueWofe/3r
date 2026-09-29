import { expect, test } from '@playwright/test';
import { apiContext, csrfToken, json, login, mutate, unique } from './helpers';

const categories = ['監所探訪與代禱', '更生安置與職訓', '食品採購與禮盒', '志工加入', '奉獻與收據諮詢', '其他諮詢'];
type Inquiry = {
  id: number; name: string; phone: string; email: string | null; category: string; message: string;
  status: 'new' | 'processing' | 'closed'; staff_note: string | null; version: number;
};

test('anonymous contact submission is idempotent by token and never enters public content search', async () => {
  const visitor = await apiContext();
  const member = await apiContext();
  const admin = await apiContext();
  const marker = unique('private-contact-e2e');
  const submission = {
    name: `E2E 訪客 ${marker}`,
    phone: '0900000000',
    email: `${marker}@example.test`,
    category: categories[0],
    message: `只供同工處理的合成訊息 ${marker}`,
    submission_token: crypto.randomUUID(),
    website: '',
  };
  try {
    const csrf = await csrfToken(visitor);
    const first = await json<{ message: string; reference: string }>(await visitor.post('/api/v1/public/contact', {
      data: submission, headers: { 'X-CSRF-TOKEN': csrf },
    }));
    expect(first).toMatchObject({ message: '已收到您的訊息' });
    expect(first.reference).toBeTruthy();
    expect(JSON.stringify(first)).not.toContain(submission.phone);
    expect(JSON.stringify(first)).not.toContain(submission.message);

    const replay = await json<typeof first>(await visitor.post('/api/v1/public/contact', {
      data: submission, headers: { 'X-CSRF-TOKEN': csrf },
    }));
    expect(replay).toEqual(first);
    const conflict = await visitor.post('/api/v1/public/contact', {
      data: { ...submission, message: `${submission.message} edited` },
      headers: { 'X-CSRF-TOKEN': csrf },
    });
    expect(conflict.status()).toBe(409);

    const honeypot = await visitor.post('/api/v1/public/contact', {
      data: { ...submission, submission_token: crypto.randomUUID(), website: 'bot-filled' },
      headers: { 'X-CSRF-TOKEN': csrf },
    });
    expect(honeypot.status()).toBe(422);
    const invalidCategory = await visitor.post('/api/v1/public/contact', {
      data: { ...submission, submission_token: crypto.randomUUID(), category: '不存在的分類' },
      headers: { 'X-CSRF-TOKEN': csrf },
    });
    expect(invalidCategory.status()).toBe(422);

    const publicSearch = await json<{ data: unknown[] }>(await visitor.get(`/api/v1/public/search?q=${encodeURIComponent(marker)}`));
    expect(JSON.stringify(publicSearch)).not.toContain(submission.name);
    expect(JSON.stringify(publicSearch)).not.toContain(submission.phone);
    expect(JSON.stringify(publicSearch)).not.toContain(submission.message);

    await login(member, '0900000003');
    expect((await member.get('/api/v1/contact-inquiries')).status()).toBe(403);
    await login(admin, '0900000001');
    const rows = await json<{ data: Inquiry[] }>(await admin.get(`/api/v1/contact-inquiries?category=${encodeURIComponent(submission.category)}&status=new&q=${encodeURIComponent(marker)}`));
    const inquiry = rows.data.find(row => row.message === submission.message);
    expect(inquiry).toMatchObject({ name: submission.name, phone: submission.phone, email: submission.email, category: submission.category, status: 'new', version: 1 });
    expect(inquiry!.id).toBeTruthy();

    const updated = await json<Inquiry>(await mutate(admin, 'put', `/api/v1/contact-inquiries/${inquiry!.id}`, {
      version: inquiry!.version, status: 'closed', staff_note: '合成驗收已結案',
    }));
    expect(updated).toMatchObject({ id: inquiry!.id, status: 'closed', staff_note: '合成驗收已結案', name: submission.name, message: submission.message });
    expect(updated.version).toBeGreaterThan(inquiry!.version);
    const stale = await mutate(admin, 'put', `/api/v1/contact-inquiries/${inquiry!.id}`, {
      version: inquiry!.version, status: 'processing', staff_note: '不應覆寫新狀態',
    });
    expect(stale.status()).toBe(409);

    const closedRows = await json<{ data: Inquiry[] }>(await admin.get(`/api/v1/contact-inquiries?category=${encodeURIComponent(submission.category)}&status=closed&q=${encodeURIComponent(marker)}`));
    expect(closedRows.data).toHaveLength(1);
    expect(closedRows.data[0]).toMatchObject({ status: 'closed', staff_note: '合成驗收已結案' });
  } finally {
    // Inquiries are intentionally retained as private UAT audit records; never issue a delete.
    await Promise.all([visitor.dispose(), member.dispose(), admin.dispose()]);
  }
});
