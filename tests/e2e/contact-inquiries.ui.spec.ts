import { expect, test } from '@playwright/test';
import { demoPassword, unique } from './helpers';

test('visitor submits a categorized contact message and an authorized admin finds and closes it', async ({ page }) => {
  const marker = unique('mobile-ui-contact');
  const name = `E2E 聯絡人 ${marker}`;
  const message = `需要同工了解的合成聯絡訊息 ${marker}`;
  await page.goto('/contact');
  await expect(page.getByRole('heading', { name: '與我們聯絡' })).toBeVisible();
  await page.getByLabel('姓名').fill(name);
  await page.getByLabel('電話').fill('0900000000');
  await page.getByLabel('電子郵件').fill(`${marker}@example.test`);
  await page.getByLabel('諮詢分類').selectOption('監所探訪與代禱');
  await page.getByLabel('想說的話').fill(message);
  await page.getByRole('button', { name: '送出訊息' }).click();
  await expect(page.getByText('已收到您的訊息', { exact: true })).toBeVisible();
  await expect(page.getByLabel('姓名')).toHaveValue('');

  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
  await page.goto('/app/admin/contact-inquiries');
  await expect(page.getByRole('heading', { name: '聯絡表單' })).toBeVisible();
  await page.getByLabel('分類').selectOption('監所探訪與代禱');
  await page.getByLabel('狀態').selectOption('new');
  await page.getByPlaceholder('搜尋姓名、電話或訊息').fill(marker);
  await page.getByRole('button', { name: '篩選' }).click();

  const row = page.getByRole('row').filter({ hasText: message });
  await expect(row).toBeVisible();
  await expect(row).toContainText('新訊息');
  await row.getByRole('button', { name: '查看與處理' }).click();
  await expect(page.getByText(message, { exact: true })).toBeVisible();
  await page.getByLabel('處理狀態').selectOption('closed');
  await page.getByLabel('同工備註').fill(`已確認合成內容 ${marker}`);
  await page.getByRole('button', { name: '儲存處理狀態' }).click();
  await expect(page.getByText('已更新', { exact: true })).toBeVisible();
  await expect(row).toContainText('已結案');
});
