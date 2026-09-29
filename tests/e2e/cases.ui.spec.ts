import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

test('admin creates a case and sees its owner, prison, and refreshed service-record timeline', async ({ page }) => {
  const api = await apiContext();
  try {
    await login(api, '0900000001');
    const { user: admin } = await json<{ user: { name: string } }>(await api.get('/api/v1/auth/me'));
    const { data: users } = await json<{ data: { id: number; phone: string; name: string }[] }>(await api.get('/api/v1/users'));
    const volunteer = users.find(user => user.phone === '0900000002');
    expect(volunteer, 'synthetic volunteer account exists').toBeTruthy();
    const prisonName = unique('病例UI監所');
    const renamedPrisonName = `${prisonName}-更名`;
    await loginAsAdmin(page);
    await page.goto('/app/admin/prisons');
    await expect(page.getByRole('heading', { name: '監所管理', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '新增', exact: true }).click();
    await page.getByLabel('監所名稱').fill(prisonName);
    await page.getByLabel('地址').fill('UI 合成地址');
    await page.getByLabel('狀態').selectOption({ label: '啟用' });
    const prisonCreatePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/prisons'),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const prisonCreateResponse = await prisonCreatePromise;
    expect(prisonCreateResponse.status()).toBe(200);
    const prison = await prisonCreateResponse.json() as { id: number; version: number };
    const prisonRow = page.getByRole('row').filter({ hasText: prisonName });
    await expect(prisonRow).toBeVisible();
    await expect(page.getByText('已更新', { exact: true }).first()).toBeVisible();
    await prisonRow.getByRole('button', { name: '編輯', exact: true }).click();
    await page.getByLabel('監所名稱').fill(renamedPrisonName);
    const prisonUpdatePromise = page.waitForResponse(response =>
      response.request().method() === 'PUT' && new URL(response.url()).pathname.endsWith(`/api/v1/prisons/${prison.id}`),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    expect((await prisonUpdatePromise).status()).toBe(200);
    await expect(page.getByRole('row').filter({ hasText: renamedPrisonName })).toBeVisible();

    const otherCode = unique('CASE');
    const otherName = unique('切換驗收個案');
    const otherCase = await json<{ id: number }>(await mutate(api, 'post', '/api/v1/cases', {
      code: otherCode,
      name: otherName,
      status: '在案',
      prison_id: prison.id,
      assigned_user_id: volunteer!.id,
    }));

    await page.goto('/app/admin/cases');
    await expect(page.getByRole('heading', { name: '個案紀錄', exact: true })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: otherCode })).toContainText(volunteer!.name);

    const caseCode = unique('CASE');
    const caseName = unique('立即可見個案');
    await page.getByRole('button', { name: '新增', exact: true }).click();
    await page.getByLabel('個案代碼').fill(caseCode);
    await page.getByLabel('姓名').fill(caseName);
    await page.getByLabel('狀態').fill('在案');
    await page.getByLabel('監所').selectOption(String(prison.id));
    await page.getByLabel('負責同工').selectOption(String(volunteer!.id));
    const createResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/cases'),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(200);
    const createdCase = await createResponse.json() as { id: number; assigned_user_name: string; prison: string };
    expect(createdCase).toMatchObject({ assigned_user_name: volunteer!.name, prison: renamedPrisonName });

    const newRow = page.getByRole('row').filter({ hasText: caseCode });
    await expect(newRow).toBeVisible();
    await expect(newRow).toContainText(caseName);
    await expect(newRow).toContainText(volunteer!.name);
    await expect(newRow).toContainText(renamedPrisonName);
    await expect(page.getByText('已更新', { exact: true }).first()).toBeVisible();

    await page.getByLabel('個案').selectOption(String(createdCase.id));
    const summary = unique('即時服務紀錄');
    const followUp = '一週後再聯繫';
    await page.getByLabel('服務類型').fill('電話關懷');
    await page.getByLabel('服務摘要').fill(summary);
    await page.getByLabel('後續追蹤').fill(followUp);
    await page.getByRole('button', { name: '儲存紀錄', exact: true }).click();
    await expect(page.getByText('服務紀錄已更新', { exact: true })).toBeVisible();
    const timeline = page.locator('ol.story-timeline');
    await expect(timeline).toContainText(summary);
    await expect(timeline).toContainText(followUp);
    await expect(timeline).toContainText(admin.name);

    await page.getByLabel('個案').selectOption(String(otherCase.id));
    await expect(page.getByText('此個案尚無服務紀錄。', { exact: true })).toBeVisible();
    await page.getByLabel('個案').selectOption(String(createdCase.id));
    await expect(page.locator('ol.story-timeline')).toContainText(summary);
  } finally {
    await api.dispose();
  }
});

async function loginAsAdmin(page: Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}
