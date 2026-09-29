import { expect, test } from '@playwright/test';
import { apiContext, csrfToken, json, login, mutate, unique } from './helpers';

type Product = { id: number; title: string; slug: string; status: string; category: string };
type Notification = { id: number; title?: string; message?: string; contact_inquiry_id?: number; url?: string; read?: boolean };

test('public product categories filter published items and omit draft-only categories', async () => {
  const admin = await apiContext();
  const ids: number[] = [];
  try {
    await login(admin, '0900000001');
    const suffix = unique('product-filter').toLowerCase();
    const categoryA = `E2E 分類甲 ${suffix}`;
    const categoryB = `E2E 分類乙 ${suffix}`;
    const draftCategory = `E2E 草稿分類 ${suffix}`;
    const create = async (title: string, category: string, status: 'published' | 'draft') => {
      const row = await json<Product>(await mutate(admin, 'post', '/api/v1/products', {
        title, slug: `${suffix}-${ids.length}`, body: '合成展示商品，無購買流程。', summary: '只用於公開分類篩選驗收。',
        category, status, sort_order: 0,
        metadata: {
          unit: '盒', currency: 'TWD', gallery_ids: [], spec_axes: [],
          variants: [{ id: `variant-${ids.length}`, sku: `sku-${suffix}-${ids.length}`, options: [], price: 100, stock: 8, active: true, wholesale: [] }],
        },
      }));
      ids.push(row.id);
      return row;
    };
    const first = await create(`甲類公開商品 ${suffix}`, categoryA, 'published');
    const second = await create(`乙類公開商品 ${suffix}`, categoryB, 'published');
    const draft = await create(`草稿商品 ${suffix}`, draftCategory, 'draft');

    const all = await json<{ data: Product[]; categories: string[] }>(await admin.get('/api/v1/public/products?limit=100'));
    expect(all.categories).toContain(categoryA);
    expect(all.categories).toContain(categoryB);
    expect(all.categories).not.toContain(draftCategory);
    expect(all.data.some(product => product.id === draft.id)).toBeFalsy();

    const filteredA = await json<{ data: Product[]; categories: string[] }>(await admin.get(`/api/v1/public/products?category=${encodeURIComponent(categoryA)}&limit=100`));
    expect(filteredA.data.map(product => product.id)).toContain(first.id);
    expect(filteredA.data.some(product => product.id === second.id || product.id === draft.id)).toBeFalsy();
    // The category menu remains useful while a filter is active.
    expect(filteredA.categories).toContain(categoryB);
    const filteredB = await json<{ data: Product[] }>(await admin.get(`/api/v1/public/products?category=${encodeURIComponent(categoryB)}&limit=100`));
    expect(filteredB.data.map(product => product.id)).toContain(second.id);
    expect(filteredB.data.some(product => product.id === first.id)).toBeFalsy();
  } finally {
    for (const id of ids.reverse()) {
      const response = await mutate(admin, 'delete', `/api/v1/products/${id}`);
      expect([200, 204, 404]).toContain(response.status());
    }
    await admin.dispose();
  }
});

test('new contact inquiries generate generic unread alerts only for authorized inbox readers', async () => {
  const visitor = await apiContext();
  const admin = await apiContext();
  const member = await apiContext();
  const marker = unique('contact-notification-private');
  let inquiryId: number | undefined;
  try {
    await login(admin, '0900000001');
    const before = await json<{ data: Notification[]; unread_count: number }>(await admin.get('/api/v1/notifications'));
    const csrf = await csrfToken(visitor);
    const response = await json<{ reference: string }>(await visitor.post('/api/v1/public/contact', {
      data: {
        name: `私人姓名 ${marker}`, phone: '0912345678', email: `${marker}@example.test`,
        category: '志工加入', message: `私人訊息不可複製到通知 ${marker}`,
        submission_token: crypto.randomUUID(), website: '',
      },
      headers: { 'X-CSRF-TOKEN': csrf },
    }));
    expect(response.reference).toBeTruthy();

    const after = await json<{ data: Notification[]; unread_count: number }>(await admin.get('/api/v1/notifications'));
    const inquiryRows = await json<{ data: { id: number }[] }>(await admin.get(`/api/v1/contact-inquiries?q=${encodeURIComponent(marker)}`));
    inquiryId = inquiryRows.data[0].id;
    const alert = after.data.find(notification => notification.contact_inquiry_id === inquiryId);
    expect(alert, 'authorized inbox reader receives a contact alert').toBeTruthy();
    inquiryId = alert!.contact_inquiry_id;
    expect(inquiryId).toBeTruthy();
    expect(alert).toMatchObject({ title: '新的聯絡訊息', message: '收到新的聯絡表單，請查看並處理。', read: false });
    expect(alert!.url).toBe(`/app/admin/contact-inquiries?id=${inquiryId}`);
    expect(after.unread_count).toBe(after.data.filter(item => !item.read).length);
    expect(before.data.some(item => item.id === alert!.id)).toBeFalsy();
    expect(JSON.stringify(alert)).not.toContain(marker);
    expect(JSON.stringify(alert)).not.toContain('0912345678');

    await login(member, '0900000003');
    const memberInbox = await json<{ data: Notification[] }>(await member.get('/api/v1/notifications'));
    expect(memberInbox.data.some(notification => notification.contact_inquiry_id === inquiryId)).toBeFalsy();
    expect(JSON.stringify(memberInbox)).not.toContain(marker);
    expect((await member.get('/api/v1/contact-inquiries')).status()).toBe(403);
  } finally {
    // Contact inquiries intentionally remain private, closed or open UAT records; no delete route exists.
    await Promise.all([visitor.dispose(), admin.dispose(), member.dispose()]);
  }
});

test('contact honeypot rejects a bot submission without storing an inquiry', async () => {
  const visitor = await apiContext();
  const admin = await apiContext();
  const marker = unique('honeypot-contact-private');
  try {
    await login(admin, '0900000001');
    const csrf = await csrfToken(visitor);
    const rejected = await visitor.post('/api/v1/public/contact', {
      data: {
        name: `Bot ${marker}`, phone: '0900000000', category: '其他諮詢',
        message: `不應建立聯絡紀錄 ${marker}`, submission_token: crypto.randomUUID(), website: 'filled-by-bot',
      },
      headers: { 'X-CSRF-TOKEN': csrf },
    });
    expect(rejected.status()).toBe(422);
    const found = await json<{ data: unknown[] }>(await admin.get(`/api/v1/contact-inquiries?q=${encodeURIComponent(marker)}`));
    expect(found.data).toHaveLength(0);
  } finally {
    // Deliberately send one honeypot attempt only. Rate-limit verification belongs in isolated backend tests.
    await Promise.all([visitor.dispose(), admin.dispose()]);
  }
});
