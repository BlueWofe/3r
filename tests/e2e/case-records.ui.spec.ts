import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test('case records survive page reloads and tab switches preserve selectable cases', async ({ page }, testInfo) => {
  const api = await apiContext();
  let caseId: number | undefined;
  try {
    await login(api, '0900000001');
    const caseRow = await json<{ id: number; code: string; name: string }>(await mutate(api, 'post', '/api/v1/cases', {
      code: unique('UI-CASE-RECORD'), name: unique('服務紀錄持久化個案'), status: '在案',
    }));
    caseId = caseRow.id;

    await signInAsAdmin(page);
    await page.goto('/app/admin/cases');
    await expect(page.getByRole('heading', { name: '個案紀錄', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '服務紀錄', exact: true }).click();
    const casePicker = page.getByRole('combobox', { name: /^個案/ });
    await expect(casePicker.locator(`option[value="${caseId}"]`)).toContainText(caseRow.name);
    await casePicker.selectOption(String(caseId));
    const summary = unique('Reloadable service record');
    const followUp = `後續追蹤 ${unique('persisted')}`;
    await page.getByLabel('服務類型').fill('E2E 持久化關懷');
    await page.getByLabel('服務摘要').fill(summary);
    await page.getByLabel('後續追蹤').fill(followUp);
    const saveResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(`/api/v1/cases/${caseId}/records`),
    );
    await page.getByRole('button', { name: '儲存紀錄', exact: true }).click();
    expect((await saveResponsePromise).ok()).toBeTruthy();
    await expect(page.getByText('已更新', { exact: true })).toBeVisible();
    await expect(page.locator('ol.story-timeline')).toContainText(summary);
    await expect(page.locator('ol.story-timeline')).toContainText(followUp);

    // Switching views must not lose the list data or selected case in the record pane.
    await page.getByRole('button', { name: '個案名冊', exact: true }).click();
    await expect(page.getByRole('row').filter({ hasText: caseRow.code })).toContainText(caseRow.name);
    await page.getByRole('button', { name: '服務紀錄', exact: true }).click();
    await expect(casePicker.locator(`option[value="${caseId}"]`)).toContainText(caseRow.name);
    await expect(casePicker).toHaveValue(String(caseId));
    await expect(page.locator('ol.story-timeline')).toContainText(summary);

    // A new document request rehydrates the record from the API instead of local component state.
    await page.reload();
    await expect(page.getByRole('button', { name: '服務紀錄', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const reloadedPicker = page.getByRole('combobox', { name: /^個案/ });
    await expect(reloadedPicker.locator(`option[value="${caseId}"]`)).toContainText(caseRow.name);
    await expect(reloadedPicker).toHaveValue(String(caseId));
    await expect(page.locator('ol.story-timeline')).toContainText(summary);
    await expect(page.locator('ol.story-timeline')).toContainText(followUp);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath('case-records.png'), fullPage: true });
  } finally {
    if (caseId) {
      const removed = await mutate(api, 'delete', `/api/v1/cases/${caseId}`);
      expect([200, 204, 404]).toContain(removed.status());
    }
    await api.dispose();
  }
});

test('meeting administration displays role names while keeping the role selection editable', async ({ page }) => {
  const api = await apiContext();
  let meetingId: number | undefined;
  try {
    await login(api, '0900000001');
    const roles = await json<{ data: { id: number; name: string }[] }>(await api.get('/api/v1/role-options'));
    const role = roles.data[0];
    expect(role).toBeTruthy();

    await signInAsAdmin(page);
    await page.goto('/app/admin/meetings');
    await expect(page.getByRole('heading', { name: '會議管理', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '新增', exact: true }).click();
    const title = unique('Role label UI meeting');
    await page.getByLabel('會議名稱').fill(title);
    await page.getByLabel('日期').fill(new Date().toISOString().slice(0, 10));
    await page.getByLabel('議程').fill('會議角色名稱呈現驗收');
    await page.getByLabel('紀錄').fill('合成資料');
    await page.getByLabel('決議').fill('不產生外部通知');
    await page.getByLabel('可查看角色').selectOption(String(role!.id));
    const saveResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/meetings'),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const saved = await saveResponsePromise;
    expect(saved.ok()).toBeTruthy();
    const responseBody = await saved.json() as { id: number; role_names?: string[] };
    meetingId = responseBody.id;
    expect(responseBody.role_names).toContain(role!.name);

    const row = page.getByRole('row').filter({ hasText: title });
    await expect(row).toBeVisible();
    const roleCell = row.locator('td[data-label="可查看角色"]');
    await expect(roleCell).toHaveText(role!.name);
    await expect(roleCell).not.toHaveText(String(role!.id));
    await row.getByRole('button', { name: '編輯', exact: true }).click();
    await expect(page.getByLabel('可查看角色')).toHaveValues([String(role!.id)]);
    await expect(page.getByLabel('可查看角色').locator('option:checked')).toHaveText(role!.name);
  } finally {
    if (meetingId) {
      const removed = await mutate(api, 'delete', `/api/v1/meetings/${meetingId}`);
      expect([200, 204, 404]).toContain(removed.status());
    }
    await api.dispose();
  }
});

async function signInAsAdmin(page: Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}
