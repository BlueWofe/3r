import { expect, test } from '@playwright/test';

const session = {
  id: 23, title: '深連結合成課程', class_name: '更新班', prison: '合成監所', location: '第二教室',
  service_date: '2035-02-03', start_time: '09:00', end_time: '10:00', status: 'scheduled', version: 1,
  participant_count: 8, assignments: [], invitations: [], events: [],
};

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/auth/me', route => route.fulfill({ json: { user: { id: 1, name: '合成管理員', permissions: ['orders.read.all', 'orders.update.all', 'contacts.read.all', 'contacts.update.all', 'schedule.read.all', 'schedule.update.all'] } } }));
  await page.route('**/api/v1/auth/csrf', route => route.fulfill({ json: { csrf_token: 'mock-csrf' } }));
  await page.route('**/api/v1/notifications', route => route.fulfill({ json: { data: [], unread_count: 0 } }));
});

test('order notification focuses its card without opening detail and keeps manual detail action', async ({ page }) => {
  const order = { id: 701, order_number: 'R2035000701', customer_name: '合成訂購人', customer_phone: '0911111111', created_at: '2035-02-01T08:00:00+08:00', delivery_method: 'pickup', items: [], total: 300, status: 'new', version: 1, staff_note: '' };
  await page.route('**/api/v1/orders?**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/orders/701', route => route.fulfill({ json: order }));
  await page.goto('/app/admin/orders?id=701');
  const card = page.locator('[data-order-id="701"]');
  await expect(card).toHaveClass(/notification-focus/);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await card.getByRole('button', { name: '查看訂單' }).click();
  await expect(page.getByRole('dialog')).toContainText('R2035000701');
});

test('contact notification focuses its row without opening detail and keeps manual action', async ({ page }) => {
  const inquiry = { id: 702, name: '合成聯絡人', phone: '0922222222', email: '', category: '其他諮詢', message: '合成訊息', status: 'new', staff_note: '', version: 1, created_at: '2035-02-01T08:00:00+08:00' };
  await page.route('**/api/v1/contact-inquiries?**', route => route.fulfill({ json: { data: [inquiry] } }));
  await page.goto('/app/admin/contact-inquiries?id=702');
  const row = page.locator('[data-inquiry-id="702"]');
  await expect(row).toHaveClass(/notification-focus/);
  await expect(page.getByRole('heading', { name: '聯絡訊息' })).toHaveCount(0);
  await row.getByRole('button', { name: '查看與處理' }).click();
  await expect(page.getByRole('heading', { name: '聯絡訊息' })).toBeVisible();
});

test('schedule notification focuses its row without opening actions and keeps manual action', async ({ page }) => {
  await page.route('**/api/v1/sessions/23', route => route.fulfill({ json: session }));
  await page.route('**/api/v1/sessions?**', route => route.fulfill({ json: { data: [session] } }));
  await page.route('**/api/v1/teachers', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/prisons/options', route => route.fulfill({ json: { data: [] } }));
  await page.goto('/app/admin/schedule?session_id=23');
  const row = page.locator('[data-session-id="23"]');
  await expect(row).toHaveClass(/linked-session/);
  await expect(page.locator('.modal')).toHaveCount(0);
  await row.getByRole('button', { name: '管理場次' }).click();
  await expect(page.locator('.modal')).toContainText('深連結合成課程');
});

test('group-news notification focuses its list card without opening the article', async ({ page }) => {
  const article = { id: 81, title: '合成小組消息', summary: '列表摘要', group_names: ['合成小組'], published_at: '2035-02-01T08:00:00+08:00', author_name: '合成編輯' };
  await page.route('**/api/v1/group-news', route => route.fulfill({ json: { data: [article] } }));
  await page.route('**/api/v1/groups/options', route => route.fulfill({ json: { data: [] } }));
  await page.goto('/app/group-news?content_id=81');
  const card = page.locator('[data-content-id="81"]');
  await expect(card).toHaveClass(/notification-focus/);
  await expect(page).toHaveURL(/\/app\/group-news\?content_id=81$/);
  await card.click();
  await expect(page).toHaveURL(/\/app\/group-news\/81$/);
});
