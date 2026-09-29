import { test, expect } from '@playwright/test';
import { demoPassword } from './helpers';

test('homepage shows the ministry and hope timelines without mobile horizontal overflow', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /陪伴旅程/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: /更新的足跡/ })).toBeVisible();
  const ministryTimeline = page.locator('ol, ul').filter({ hasText: /走進高牆/ });
  await expect(ministryTimeline).toContainText('建立信任');
  await expect(ministryTimeline).toContainText('預備復歸');
  await expect(ministryTimeline).toContainText('社區同行');
  await expect(page.locator('body')).toContainText(/示範故事/);
  await expect(page.locator('body')).not.toContainText('示範統計資料');

  const widths = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client + 2);
});

test('商品與班別有各自的管理導覽入口', async ({ page }) => {
  await page.goto('/login');
  await page.locator('input').nth(0).fill('0900000001');
  await page.locator('input[type="password"]').fill(demoPassword!);
  await page.getByRole('button', { name: '登入' }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
  await page.goto('/app');

  const productsLink = page.getByRole('link', { name: '商品管理' });
  const classesLink = page.getByRole('link', { name: '班別管理' });
  await expect(productsLink).toBeVisible();
  await expect(classesLink).toBeVisible();

  await productsLink.click();
  await expect(page).toHaveURL(/\/app\/admin\/products$/);
  await expect(page.getByRole('heading', { name: /商品/ })).toBeVisible();
  await page.goto('/app');
  await classesLink.click();
  await expect(page).toHaveURL(/\/app\/admin\/classes$/);
  await expect(page.getByRole('heading', { name: /班別/ })).toBeVisible();
});
