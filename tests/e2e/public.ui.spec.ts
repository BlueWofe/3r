import { test, expect } from '@playwright/test';
import { demoPassword } from './helpers';

test('login cannot submit before client code is ready on a slow connection', async ({ page }) => {
  let release!: () => void;
  const loaded = new Promise<void>(resolve => { release = resolve; });
  await page.route('**/_nuxt/*.js', async route => {
    await loaded;
    await route.continue();
  });
  try {
    await page.goto('/login', { waitUntil: 'commit' });
    const phone = page.getByLabel('手機號碼');
    await expect(phone).toBeVisible();
    await expect(phone).toBeDisabled();
    await expect(page.locator('form').getByRole('button', { name: '登入', exact: true })).toBeDisabled();
    release();
    await expect(phone).toBeEnabled();
    await phone.fill('0900000001');
    await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
    await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
  } finally {
    release();
  }
});

test('public site, login and calendar render at desktop and mobile sizes', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('body')).toContainText(/示範|服事|協會|3R/i);

  await page.goto('/login');
  await expect(page.locator('input').first()).toBeVisible();
  const fields = page.locator('input');
  await fields.nth(0).fill('0900000001');
  await fields.nth(1).fill(demoPassword!);
  const submit = page.locator('form').getByRole('button', { name: '登入', exact: true });
  await submit.click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });

  await page.goto('/calendar');
  await expect(page.locator('body')).toContainText(/行事曆|排程|課程|登入/i);
  const dimensions = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client + 2);
});
