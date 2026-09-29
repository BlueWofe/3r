import { test, expect } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

test('homepage shows the ministry and hope timelines without mobile horizontal overflow', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /陪伴旅程/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: /更新的足跡/ })).toBeVisible();
  const ministryTimeline = page.locator('ol, ul').filter({ hasText: /走進高牆/ });
  await expect(ministryTimeline).toContainText('建立信任');
  await expect(ministryTimeline).toContainText('預備復歸');
  await expect(ministryTimeline).toContainText('社區同行');
  await expect(page.locator('body')).not.toContainText('示範統計資料');

  const storySection = page.locator('section').filter({ has: page.getByRole('heading', { name: '更新的足跡' }) }).first();
  const storyLinks = storySection.locator('a[href^="/news/"]');
  const storyCount = await storyLinks.count();
  expect(storyCount).toBeGreaterThanOrEqual(1);
  expect(storyCount).toBeLessThanOrEqual(3);
  for (let index = 0; index < storyCount; index++) {
    const link = storyLinks.nth(index);
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', /^\/news\/\d+$/);
    await expect(link).toContainText(/\d{4}年\d{1,2}月\d{1,2}日/);
  }

  const widths = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client + 2);
});

test('產品與班別有各自的管理導覽入口', async ({ page, isMobile }) => {
  await page.goto('/login');
  await page.locator('input').nth(0).fill('0900000001');
  await page.locator('input[type="password"]').fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入' }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
  await page.goto('/app');
  if (isMobile)
    await page.getByRole('button', { name: '管理工作台', exact: true }).click();
  await page.locator('summary').filter({ hasText: '官網內容' }).click();

  const productsLink = page.getByRole('link', { name: '產品管理' });
  const classesLink = page.getByRole('link', { name: '班別管理' });
  await expect(productsLink).toBeVisible();

  await productsLink.click();
  await expect(page).toHaveURL(/\/app\/admin\/products$/);
  await expect(page.getByRole('heading', { name: '產品管理' })).toBeVisible();
  await page.goto('/app');
  if (isMobile)
    await page.getByRole('button', { name: '管理工作台', exact: true }).click();
  await page.locator('summary').filter({ hasText: '課務與關懷' }).click();
  await expect(classesLink).toBeVisible();
  await classesLink.click();
  await expect(page).toHaveURL(/\/app\/admin\/classes$/);
  await expect(page.getByRole('heading', { name: /班別/ })).toBeVisible();
});

test('admin can create and edit a product with multiple axes and a quantity tier', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/app/admin/products');
  await expect(page.getByRole('heading', { name: '產品管理' })).toBeVisible();
  await page.getByRole('button', { name: /新增產品|新增食品/ }).click();

  const productName = `E2E 商品 ${Date.now()}`;
  const editedName = `${productName} 已編輯`;
  const slug = `e2e-product-${Date.now()}`;
  await page.getByLabel('名稱', { exact: true }).fill(productName);
  await page.getByLabel('網址代稱').fill(slug);
  await page.getByLabel('分類').fill('UI 驗收');
  await page.getByLabel('摘要').fill('建立含多規格與數量階梯的合成商品');
  await page.getByLabel('介紹').fill('E2E 測試用資料');
  await page.getByLabel('狀態').selectOption('published');

  await page.getByRole('button', { name: '新增規格軸' }).click();
  await page.getByRole('button', { name: '新增規格軸' }).click();
  await page.getByLabel('規格名稱').nth(0).fill('口味');
  await page.getByLabel('選項（逗號分隔）').nth(0).fill('原味,可可');
  await page.getByLabel('規格名稱').nth(1).fill('包裝');
  await page.getByLabel('選項（逗號分隔）').nth(1).fill('6入');

  await page.getByLabel('SKU').nth(0).fill(`SKU-${slug}`);
  await page.getByLabel('規格選項（依軸順序）').nth(0).fill('原味,6入');
  await page.getByLabel('單價').nth(0).fill('120');
  await page.getByLabel('庫存').nth(0).fill('40');
  await page.getByRole('button', { name: '新增大量優惠' }).click();
  await page.getByLabel('數量門檻').fill('5');
  await page.getByLabel('優惠單價').fill('110');
  await page.getByRole('button', { name: /儲存產品|儲存食品/ }).click();

  const productRow = page.getByRole('row').filter({ hasText: productName });
  await expect(productRow).toBeVisible();
  await productRow.getByRole('button', { name: '編輯' }).click();
  await expect(page.getByRole('heading', { name: /編輯/ })).toBeVisible();
  await expect(page.getByLabel('規格名稱').nth(0)).toHaveValue('口味');
  await expect(page.getByLabel('數量門檻')).toHaveValue('5');
  await page.getByLabel('名稱', { exact: true }).fill(editedName);
  await page.getByLabel('單價').nth(0).fill('125');
  await page.getByLabel('優惠單價').fill('115');
  await page.getByRole('button', { name: /儲存產品|儲存食品/ }).click();
  await expect(page.getByRole('row').filter({ hasText: editedName })).toBeVisible();
});

test('admin can create, preview and edit a recurring class template', async ({ page }) => {
  const api = await apiContext();
  await login(api, '0900000001');
  const prisonName = unique('UI班別監所');
  const prison = await json<{ id: number }>(await mutate(api, 'post', '/api/v1/prisons', {
    name: prisonName,
    active: true,
  }));
  await api.dispose();

  await loginAsAdmin(page);
  await page.goto('/app/admin/classes');
  await expect(page.getByRole('heading', { name: '班別管理' })).toBeVisible();
  await page.getByRole('button', { name: '新增班別' }).click();

  const className = `E2E 班別 ${Date.now()}`;
  const editedName = `${className} 已編輯`;
  await page.getByLabel('班別名稱').fill(className);
  await page.getByLabel('監所').selectOption(String(prison.id));
  await page.getByLabel('地點').fill('E2E 教室');
  await page.getByLabel('參與人數').fill('4');
  await page.getByLabel('開始日').fill(taipeiToday());

  await page.getByRole('button', { name: '預覽 90 天' }).click();
  await expect(page.getByText(/預覽 \d+ 場，略過/)).toBeVisible();
  await expect(page.locator('table tbody tr').first()).toBeVisible();
  await page.getByLabel('頻率').selectOption('monthly_date');
  await page.getByLabel('每月日期', { exact: true }).fill('31');
  await page.getByRole('button', { name: '預覽 90 天' }).click();
  await expect(page.getByText(/預覽 \d+ 場，略過/)).toBeVisible();
  await page.getByRole('button', { name: '儲存班別' }).click();

  const classRow = page.getByRole('row').filter({ hasText: className });
  await expect(classRow).toBeVisible();
  await classRow.getByRole('button', { name: '編輯／預覽' }).click();
  await expect(page.getByRole('heading', { name: '編輯班別' })).toBeVisible();
  await expect(page.getByLabel('每月日期', { exact: true })).toHaveValue('31');
  await page.getByLabel('班別名稱').fill(editedName);
  await page.getByLabel('地點').fill('已更新教室');
  await page.getByRole('button', { name: '預覽 90 天' }).click();
  await expect(page.getByText(/預覽 \d+ 場，略過/)).toBeVisible();
  await page.getByRole('button', { name: '儲存班別' }).click();
  await expect(page.getByRole('row').filter({ hasText: editedName })).toContainText('已更新教室');
});

async function loginAsAdmin(page: import('@playwright/test').Page) {
  await page.goto('/login');
  await page.locator('input').nth(0).fill('0900000001');
  await page.locator('input[type="password"]').fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入' }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}

function taipeiToday() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  const year = parts.find(part => part.type === 'year')!.value;
  const month = parts.find(part => part.type === 'month')!.value;
  const day = parts.find(part => part.type === 'day')!.value;
  return `${year}-${month}-${day}`;
}
