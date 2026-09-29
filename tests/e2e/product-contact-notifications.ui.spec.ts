import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

type Product = { id: number; title: string; slug: string; category: string; status: string };

test('published product category filtering and contact CTAs preserve their exact request intent', async ({ page }) => {
  const admin = await apiContext();
  const productIds: number[] = [];
  try {
    await login(admin, '0900000001');
    const suffix = unique('catalog-contact-ui').toLowerCase();
    const categoryA = `E2E食品分類甲 ${suffix}`;
    const categoryB = `E2E食品分類乙 ${suffix}`;
    const first = await createProduct(admin, suffix, `分類甲商品 ${suffix}`, categoryA, productIds);
    const second = await createProduct(admin, suffix, `分類乙商品 ${suffix}`, categoryB, productIds);

    await page.goto('/food');
    await expect(page.getByRole('heading', { name: '愛心好食' })).toBeVisible();
    const productFilter = page.getByLabel('產品分類');
    await expect(productFilter.locator(`option[value="${categoryA}"]`)).toBeAttached();
    await expect(productFilter.locator(`option[value="${categoryB}"]`)).toBeAttached();
    const firstCard = page.getByRole('link', { name: new RegExp(first.title) });
    const secondCard = page.getByRole('link', { name: new RegExp(second.title) });
    await expect(firstCard).toBeVisible();
    await expect(secondCard).toBeVisible();

    await productFilter.selectOption(categoryA);
    await expect(firstCard).toBeVisible();
    await expect(secondCard).toHaveCount(0);
    await expect(firstCard).toContainText(categoryA);
    await expect(page).toHaveURL(new RegExp('category='));
    await page.reload();
    await expect(page.getByLabel('產品分類')).toHaveValue(categoryA);
    await expect(firstCard).toBeVisible();
    await expect(secondCard).toHaveCount(0);

    const bulkLink = page.getByRole('link', { name: '登記大宗洽詢', exact: true });
    await expect(bulkLink).toHaveAttribute('href', '/contact?category=大宗認購專案');
    await bulkLink.click();
    await expect(page).toHaveURL(/\/contact\?category=/);
    await expect(page.getByLabel('諮詢分類')).toHaveValue('大宗認購專案');

    await page.goto('/food');
    const tastingLink = page.getByRole('link', { name: '登記試吃', exact: true });
    await expect(tastingLink).toHaveAttribute('href', '/contact?category=試吃');
    await tastingLink.click();
    await expect(page.getByLabel('諮詢分類')).toHaveValue('試吃');
  } finally {
    for (const id of productIds.reverse()) {
      const response = await mutate(admin, 'delete', `/api/v1/products/${id}`);
      expect([200, 204, 404]).toContain(response.status());
    }
    await admin.dispose();
  }
});

test('contact notification bell shows a generic unread alert and opens the authorized inquiry', async ({ page }, testInfo) => {
  const visitor = await apiContext();
  let inquiryId: number | undefined;
  const marker = unique('contact-bell-ui-private');
  const name = `私人聯絡人 ${marker}`;
  const phone = '0922223333';
  const privateMessage = `不可放進通知內容的聯絡訊息 ${marker}`;
  try {
    if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 800 });
    await signInAsAdmin(page);
    await page.goto('/app');
    const token = await json<{ csrf_token: string }>(await visitor.get('/api/v1/auth/csrf'));
    const created = await json<{ reference: string }>(await visitor.post('/api/v1/public/contact', {
      data: {
        name, phone, email: `${marker}@example.test`, category: '志工加入', message: privateMessage,
        submission_token: crypto.randomUUID(), website: '',
      },
      headers: { 'X-CSRF-TOKEN': token.csrf_token },
    }));
    expect(created.reference).toBeTruthy();

    const inquiryRows = await json<{ data: { id: number }[] }>(await page.request.get(`/api/v1/contact-inquiries?q=${encodeURIComponent(marker)}`));
    inquiryId = inquiryRows.data[0].id;
    const notifications = await json<{ data: { id: number; contact_inquiry_id?: number }[] }>(await page.request.get('/api/v1/notifications'));
    const notice = notifications.data.find(item => item.contact_inquiry_id === inquiryId)!;
    const bell = page.getByRole('button', { name: '通知收件匣', exact: true });
    await expect(bell).toBeVisible();
    await bell.click();
    const popover = page.locator('.notification-popover');
    await expect(popover).toContainText('新的聯絡訊息');
    await expect(page.getByLabel(/\d+ 則未讀通知/)).toBeVisible();
    await expect(popover).toContainText('收到新的聯絡表單，請查看並處理。');
    await expect(popover).not.toContainText(name);
    await expect(popover).not.toContainText(phone);
    await expect(popover).not.toContainText(privateMessage);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    const bounds = await popover.boundingBox();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(await page.evaluate(() => innerWidth));
    await page.screenshot({ path: testInfo.outputPath('notification-bell.png'), fullPage: true });

    const notificationButton = popover.locator(`[data-notification-id="${notice.id}"]`);
    await notificationButton.click();
    await expect(page).toHaveURL(/\/app\/admin\/contact-inquiries\?id=\d+/);
    await expect(page.getByRole('heading', { name: '聯絡表單', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: '聯絡訊息', exact: true })).toBeVisible();
    await expect(page.locator('.dialog').getByText(name, { exact: true })).toBeVisible();
    await expect(page.locator('.dialog').getByText(privateMessage, { exact: true })).toBeVisible();
    const id = Number(new URL(page.url()).searchParams.get('id'));
    expect(id).toBe(inquiryId);
  } finally {
    // Contact inquiries and their private notification history are retained as UAT records.
    await visitor.dispose();
  }
  expect(inquiryId).toBeGreaterThan(0);
});

test('ordinary members cannot retrieve a contact inquiry or its notification', async () => {
  const visitor = await apiContext();
  const admin = await apiContext();
  const member = await apiContext();
  const marker = unique('contact-bell-member-private');
  try {
    await login(admin, '0900000001');
    const csrf = await (await visitor.get('/api/v1/auth/csrf')).json().then(body => body.csrf_token as string);
    await visitor.post('/api/v1/public/contact', {
      data: {
        name: `私訊 ${marker}`, phone: '0933334444', category: '其他諮詢', message: `管理員才可見 ${marker}`,
        submission_token: crypto.randomUUID(), website: '',
      },
      headers: { 'X-CSRF-TOKEN': csrf },
    }).then(async response => expect(response.ok()).toBeTruthy());

    await login(member, '0900000003');
    const inbox = await json<{ data: { contact_inquiry_id?: number; title?: string; message?: string }[] }>(await member.get('/api/v1/notifications'));
    expect(JSON.stringify(inbox)).not.toContain(marker);
    expect(inbox.data.some(item => item.title === '新的聯絡訊息' || item.contact_inquiry_id)).toBeFalsy();
    expect((await member.get('/api/v1/contact-inquiries')).status()).toBe(403);
  } finally {
    await Promise.all([visitor.dispose(), admin.dispose(), member.dispose()]);
  }
});

async function createProduct(api: Awaited<ReturnType<typeof apiContext>>, suffix: string, title: string, category: string, ids: number[]) {
  const product = await json<Product>(await mutate(api, 'post', '/api/v1/products', {
    title, slug: `${suffix}-${ids.length}`, body: '合成展示資料', summary: 'E2E category filter product',
    category, status: 'published', sort_order: 0,
    metadata: {
      unit: '盒', currency: 'TWD', gallery_ids: [], spec_axes: [],
      variants: [{ id: `variant-${ids.length}-${suffix}`, sku: `sku-${ids.length}-${suffix}`, options: [], price: 100, stock: 8, active: true, wholesale: [] }],
    },
  }));
  ids.push(product.id);
  return product;
}

async function signInAsAdmin(page: Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}
