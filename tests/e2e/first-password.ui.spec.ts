import { expect, test, type Page } from '@playwright/test';

const initialPassword = 'Initial-Test-123';
const replacementPassword = 'New-Synthetic-456';

type MockUser = { id: number; name: string; phone: string; must_change_password: boolean; permissions: string[] };

async function mockAuth(page: Page, initialUser: MockUser | null, onPassword?: (body: any) => { status: number; body: unknown }, loginUser?: MockUser) {
  let user = initialUser;
  let otpPurpose = '';
  let passwordUpdates = 0;
  await page.route('**/api/v1/**', route => route.fulfill({ json: {} }));
  await page.route('**/api/v1/auth/csrf', route => route.fulfill({ json: { csrf_token: 'synthetic-csrf' } }));
  await page.route('**/api/v1/auth/me', route => user
    ? route.fulfill({ json: { user } })
    : route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
  await page.route('**/api/v1/auth/login', async route => {
    user = user || loginUser || { id: 700, name: '合成新同工', phone: '0900000000', must_change_password: false, permissions: ['donations.read.own'] };
    await route.fulfill({ json: { user } });
  });
  await page.route('**/api/v1/auth/logout', async route => {
    user = null;
    await route.fulfill({ json: { message: '已登出' } });
  });
  await page.route('**/api/v1/auth/otp', async route => {
    otpPurpose = (await route.request().postDataJSON()).purpose;
    await route.fulfill({ json: { message: '已送出' } });
  });
  await page.route('**/api/v1/auth/register', async route => {
    user = { id: 701, name: '合成註冊會員', phone: '0900000000', must_change_password: false, permissions: ['donations.read.own'] };
    await route.fulfill({ json: { message: '註冊成功', user } });
  });
  await page.route('**/api/v1/auth/password', async route => {
    passwordUpdates += 1;
    const result = onPassword?.(await route.request().postDataJSON()) || { status: 200, body: { message: '密碼已更新' } };
    if (result.status < 300 && user) user = { ...user, must_change_password: false };
    await route.fulfill({ status: result.status, json: result.body });
  });
  return { getUser: () => user, getOtpPurpose: () => otpPurpose, getPasswordUpdates: () => passwordUpdates };
}

async function signIn(page: Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000000');
  await page.getByLabel('密碼', { exact: true }).fill(initialPassword);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
}

test('first-login password change is required, errors stay on page, and success opens the workspace', async ({ page }, testInfo) => {
  if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 800 });
  const mock = await mockAuth(page, null, body => body.current_password !== initialPassword
    ? { status: 422, body: { message: '目前密碼錯誤。' } }
    : { status: 200, body: { message: '密碼已更新' } },
  { id: 700, name: '合成待改密碼同工', phone: '0900000000', must_change_password: true, permissions: ['donations.read.own'] });

  await signIn(page);
  await expect(page).toHaveURL(/\/change-password$/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2)).toBeTruthy();
  await page.getByRole('link', { name: '回到協會官網' }).click();
  if (testInfo.project.name === 'mobile') await page.getByRole('button', { name: '開啟導覽選單' }).click();
  await page.getByRole('link', { name: '聯絡我們', exact: true }).click();
  await expect(page).toHaveURL(/\/contact$/);
  await page.goto('/app/profile');
  await expect(page).toHaveURL(/\/change-password$/);

  await page.getByLabel('目前密碼', { exact: true }).fill(initialPassword);
  await page.getByLabel('新密碼', { exact: true }).fill(initialPassword);
  await page.getByLabel('再次輸入新密碼', { exact: true }).fill(initialPassword);
  await page.getByRole('button', { name: '更新密碼並進入工作台', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('不能與初始密碼相同');
  await expect(page).toHaveURL(/\/change-password$/);
  expect(mock.getPasswordUpdates()).toBe(0);

  await page.getByLabel('新密碼', { exact: true }).fill('short9');
  await page.getByLabel('再次輸入新密碼', { exact: true }).fill('short9');
  await page.getByRole('button', { name: '更新密碼並進入工作台', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('至少需要 10');
  await expect(page).toHaveURL(/\/change-password$/);
  expect(mock.getPasswordUpdates()).toBe(0);

  await page.getByLabel('新密碼', { exact: true }).fill(replacementPassword);
  await page.getByLabel('再次輸入新密碼', { exact: true }).fill('Different-Synthetic-456');
  await page.getByRole('button', { name: '更新密碼並進入工作台', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('不一致');
  await expect(page).toHaveURL(/\/change-password$/);
  expect(mock.getPasswordUpdates()).toBe(0);

  await page.getByLabel('再次輸入新密碼', { exact: true }).fill(replacementPassword);
  await page.getByLabel('目前密碼', { exact: true }).fill('Wrong-Current-123');
  await page.getByRole('button', { name: '更新密碼並進入工作台', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('目前密碼錯誤');
  await expect(page).toHaveURL(/\/change-password$/);
  expect(mock.getPasswordUpdates()).toBe(1);

  await page.getByLabel('目前密碼', { exact: true }).fill(initialPassword);
  await page.getByRole('button', { name: '更新密碼並進入工作台', exact: true }).click();
  await expect.poll(() => mock.getUser()?.must_change_password).toBe(false);
  await expect(page).toHaveURL(/\/app\/profile$/);
  await expect(page.getByRole('heading', { name: '我的帳戶', exact: true })).toBeVisible();
  expect(mock.getPasswordUpdates()).toBe(2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2)).toBeTruthy();
});

test('forced-change user can log out and OTP registration remains a normal account', async ({ page }) => {
  const mock = await mockAuth(page, null, undefined, { id: 702, name: '合成待改密碼同工', phone: '0900000000', must_change_password: true, permissions: ['donations.read.own'] });
  await signIn(page);
  await expect(page).toHaveURL(/\/change-password$/);
  await expect(page.getByRole('heading', { name: '設定您的新密碼', exact: true })).toBeVisible();
  await page.getByRole('button', { name: '登出並返回官網', exact: true }).click();
  await expect(page).toHaveURL(/\/$/);
  expect(mock.getUser()).toBeNull();

  await page.goto('/login');
  await page.getByRole('button', { name: '註冊', exact: true }).click();
  await page.getByLabel('姓名', { exact: true }).fill('合成註冊會員');
  await page.getByLabel('手機號碼', { exact: true }).fill('0900000000');
  await page.getByLabel('密碼', { exact: true }).fill(replacementPassword);
  await page.getByLabel('確認密碼', { exact: true }).fill(replacementPassword);
  await page.getByRole('button', { name: '取得驗證碼', exact: true }).click();
  await expect.poll(() => mock.getOtpPurpose()).toBe('register');
  await page.getByLabel(/驗證碼/).fill('123456');
  await page.getByRole('button', { name: '確認送出', exact: true }).click();
  await expect(page.locator('.tabs').getByRole('button', { name: '登入', exact: true })).toHaveClass(/selected/);

  await page.getByLabel('手機號碼', { exact: true }).fill('0900000000');
  await page.getByLabel('密碼', { exact: true }).fill(replacementPassword);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).toHaveURL(/\/app\/profile$/);
  expect(mock.getUser()?.must_change_password).toBe(false);
});

test('PASSWORD_CHANGE_REQUIRED API response redirects to password page without logging out', async ({ page }) => {
  const user: MockUser = { id: 703, name: '合成待改密碼同工', phone: '0900000000', must_change_password: false, permissions: ['schedule.read.own'] };
  const mock = await mockAuth(page, user);
  await page.route('**/api/v1/sessions**', route => route.fulfill({ status: 403, json: { code: 'PASSWORD_CHANGE_REQUIRED', message: '請先更新密碼。' } }));
  await page.goto('/app');
  await expect(page).toHaveURL(/\/change-password$/);
  expect(mock.getUser()?.id).toBe(user.id);
});
