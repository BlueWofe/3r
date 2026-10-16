import { test, expect } from '@playwright/test';
import { apiContext, csrfToken, json, login, mutate, unique } from './helpers';

test('public CMS, forms, resources and mock payment endpoints accept basic workflows', async () => {
  const admin = await apiContext();
  try {
    await login(admin, '0900000001');

    const slug = unique('e2e-page').toLowerCase();
    const page = await json<{ id: number; slug: string }>(await mutate(admin, 'post', '/api/v1/contents', {
      kind: 'page', title: '示範內容 E2E', slug, body: '自動驗收內容', summary: '測試資料', status: 'published', sort_order: 0, metadata: {},
    }));
    expect((await admin.get(`/api/v1/public/pages/${slug}`)).ok()).toBeTruthy();

    const form = await json<{ id: number; title: string; version: number; snapshots: { version: number }[]; fields: { key: string }[] }>(await mutate(admin, 'post', '/api/v1/forms', {
      title: unique('E2E 表單'), description: '自動驗收', status: 'published', fields: [{ key: 'feedback', label: '回饋', type: 'text', required: true }], role_ids: [],
    }));
    expect(form.version).toBe(1);
    expect(form.snapshots).toHaveLength(1);
    const editedForm = await json<typeof form>(await mutate(admin, 'put', `/api/v1/forms/${form.id}`, {
      title: form.title, description: '發布第二版', status: 'published', fields: [{ key: 'feedback', label: '回饋', type: 'text', required: true }], role_ids: [],
    }));
    expect(editedForm.version).toBe(2);
    expect(editedForm.snapshots).toHaveLength(2);
    const response = await json<{ version: number }>(await mutate(admin, 'post', `/api/v1/forms/${form.id}/responses`, { answers: { feedback: '驗收完成' } }));
    expect(response.version).toBe(2);

    const resource = await admin.post('/api/v1/resources', {
      headers: { 'X-CSRF-TOKEN': await csrfToken(admin) },
      multipart: {
        title: unique('E2E 資源'), category: '驗收',
        file: { name: 'e2e-resource.txt', mimeType: 'text/plain', buffer: Buffer.from('synthetic resource test') },
      },
    });
    expect(resource.ok(), `resource upload returned ${resource.status()}: ${await resource.text()}`).toBeTruthy();
    const resources = await json<{ data: { id: number }[] }>(await admin.get('/api/v1/resources'));
    expect(resources.data.length).toBeGreaterThan(0);

    const donation = await json<{ id: number }>(await mutate(admin, 'post', '/api/v1/donations', { amount: 100, purpose: 'E2E 模擬捐款' }));
    const simulation1 = await mutate(admin, 'post', `/api/v1/donations/${donation.id}/simulate`, { result: 'success' }, { 'Idempotency-Key': unique('payment') });
    expect(simulation1.ok()).toBeTruthy();
    const simulation2 = await json<{ status: string }>(await mutate(admin, 'post', `/api/v1/donations/${donation.id}/simulate`, { result: 'failed' }));
    expect(simulation2.status).toBe('success');
    const donationList = await json<{ data: { id: number }[] }>(await admin.get('/api/v1/donations'));
    expect(donationList.data.some(d => d.id === donation.id)).toBeTruthy();

    expect(page.id).toBeTruthy();
  } finally {
    await admin.dispose();
  }
});
