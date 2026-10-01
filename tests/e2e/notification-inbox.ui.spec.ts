import { expect, test } from '@playwright/test';

type Notice = {
  id: number;
  title: string;
  message: string;
  category: 'product' | 'course' | 'message' | 'contact';
  read: boolean;
  url: string;
};

test('notification inbox filters categories, follows safe links, and refreshes unread badge after mark all read', async ({ page }, testInfo) => {
  if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 800 });

  const rows: Notice[] = [
    { id: 101, category: 'product', title: '商品訂單通知', message: '收到新的商品訂單。', read: false, url: '/app/admin/orders?id=7' },
    { id: 102, category: 'course', title: '課程時間異動', message: '您負責的課程有異動。', read: false, url: '/app/calendar?session_id=12' },
    { id: 103, category: 'message', title: '新的小組消息', message: '您有新的小組消息。', read: true, url: '/app/group-news/13' },
    { id: 104, category: 'contact', title: '新的聯絡訊息', message: '收到新的聯絡表單，請查看並處理。', read: false, url: '/app/admin/contact-inquiries?id=14' },
  ];
  let readAllCalls = 0;
  await page.route('**/api/v1/auth/me', route => route.fulfill({ json: { user: { id: 1, name: '合成同工', permissions: ['schedule.read.own'] } } }));
  await page.route('**/api/v1/auth/csrf', route => route.fulfill({ json: { csrf_token: 'mock-csrf' } }));
  await page.route('**/api/v1/notifications/read-all', async route => {
    readAllCalls += 1;
    rows.forEach(row => { row.read = true; });
    await route.fulfill({ json: { message: '已全部標示為已讀', unread_count: 0 } });
  });
  await page.route('**/api/v1/notifications/*/read', async route => {
    const id = Number(new URL(route.request().url()).pathname.match(/notifications\/(\d+)\/read/)?.[1]);
    const row = rows.find(item => item.id === id);
    if (!row) return route.fulfill({ status: 404, json: { message: 'Not found' } });
    row.read = true;
    await route.fulfill({ json: row });
  });
  await page.route('**/api/v1/notifications', route => route.fulfill({ json: { data: rows, unread_count: rows.filter(row => !row.read).length } }));
  await page.route('**/api/v1/invitations', route => route.fulfill({ json: { data: [] } }));

  await page.goto('/app/invitations');
  const tabs = page.getByRole('tablist', { name: '通知分類' });
  await expect(tabs).toBeVisible();
  await expect(tabs.getByRole('tab')).toHaveCount(5);
  await expect(page.getByText('通知收件匣')).toBeVisible();

  const bell = page.getByRole('button', { name: '通知收件匣' });
  await expect(page.getByLabel('3 則未讀通知')).toBeVisible();
  await tabs.getByRole('tab', { name: '產品通知' }).click();
  await expect(page.getByText('商品訂單通知')).toBeVisible();
  await expect(page.getByText('課程時間異動')).toHaveCount(0);
  await tabs.getByRole('tab', { name: '全部' }).click();
  await expect(page.getByText('課程時間異動')).toBeVisible();

  await page.locator('[data-notification-id="102"]').getByRole('button').click();
  await expect(page).toHaveURL(/\/app\/calendar\?session_id=12$/);
  await page.goto('/app/invitations');
  await page.getByRole('button', { name: '全部已讀' }).click();
  await expect.poll(() => readAllCalls).toBe(1);
  await expect(page.getByLabel(/則未讀通知/)).toHaveCount(0);
  await expect(bell).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
  if (testInfo.project.name === 'mobile') {
    const tabStrip = await tabs.boundingBox();
    expect(tabStrip?.width).toBeLessThanOrEqual(await page.evaluate(() => innerWidth));
  }
});
