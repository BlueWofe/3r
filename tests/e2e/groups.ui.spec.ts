import { expect, test } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

type Group = { id: number; name: string; description: string; active: boolean; version: number; member_ids: number[] };

test('admin publishes and broadcasts a group article; a member can read it on desktop and mobile', async ({ page, browser }) => {
  // This flow uses two signed-in sessions, publication, broadcast and cleanup.
  test.setTimeout(90_000);
  const adminApi = await apiContext();
  let group: Group | undefined;
  let contentId: number | undefined;
  const slug = unique('e2e-group-news').toLowerCase();
  const groupName = unique('E2E UI 小組');
  const title = `小組消息 ${slug}`;
  try {
    await login(adminApi, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await adminApi.get('/api/v1/users'));
    const member = users.data.find(user => user.phone === '0900000003')!;

    await signIn(page, '0900000001');
    await page.goto('/app/admin/groups');
    await expect(page.getByRole('heading', { name: '小組管理' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await expect(page.getByRole('link', { name: '協會 Logo，回到官網' })).toBeVisible();
    await page.getByRole('button', { name: '新增小組' }).click();
    await page.getByLabel('小組名稱').fill(groupName);
    await page.getByLabel('說明').fill('群組文章與權限 UI 驗收');
    const memberList = page.locator('.member-options');
    // Member labels show names, not phone numbers; use the unique seeded user row from the member-options API.
    const memberOptions = await json<{ data: { id: number; name: string }[] }>(await adminApi.get('/api/v1/groups/member-options'));
    const memberName = memberOptions.data.find(user => user.id === member.id)!.name;
    await page.getByLabel('搜尋成員').fill(memberName);
    await memberList.locator('label').filter({ hasText: memberName }).locator('input[type="checkbox"]').check();
    await page.getByRole('button', { name: '儲存小組' }).click();
    await expect(page.getByRole('row').filter({ hasText: groupName })).toContainText('1 人');
    const groupRows = await json<{ data: Group[] }>(await adminApi.get('/api/v1/groups'));
    group = groupRows.data.find(row => row.name === groupName);
    expect(group).toBeTruthy();

    await page.goto('/app/admin/content?section=news');
    await expect(page.getByRole('heading', { name: '內容管理' })).toBeVisible();
    await page.getByRole('button', { name: '新增', exact: true }).click();
    await page.getByLabel('類型', { exact: true }).selectOption('news');
    await page.getByLabel('文章類型').selectOption('sharing');
    await page.getByLabel('標題', { exact: true }).fill(title);
    await page.getByLabel('網址代稱').fill(slug);
    await page.getByLabel('分類', { exact: true }).fill('小組事工');
    await page.getByLabel('作者', { exact: true }).fill('E2E 小組編輯');
    await page.getByLabel('公開範圍').selectOption('groups');
    await expect(page.locator('input[type="file"]')).toHaveCount(0);
    const groupPicker = page.getByLabel('指定小組（可複選）');
    await groupPicker.selectOption(String(group!.id));
    await page.getByLabel('狀態').selectOption('published');
    await page.getByLabel('摘要').fill('僅提供給目前小組成員閱讀');
    await page.getByRole('textbox', { name: '本文', exact: true }).fill('這則合成文章只屬於指定小組。');
    const createResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/contents'),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.ok()).toBeTruthy();
    const created = await createResponse.json() as { id: number };
    contentId = created.id;

    const articleRow = page.getByRole('row').filter({ hasText: title });
    await expect(articleRow).toBeVisible();
    await articleRow.getByRole('button', { name: '編輯' }).click();
    await page.getByRole('button', { name: '發送小組通知' }).click();
    await expect(page.getByText(/已發送給 1 位小組成員/)).toBeVisible();

    const memberContext = await browser.newContext({ baseURL: process.env.BASE_URL ?? 'http://localhost:3180', viewport: page.viewportSize() ?? undefined });
    const memberPage = await memberContext.newPage();
    try {
      await signIn(memberPage, '0900000003');
      await memberPage.goto('/app/group-news');
      await expect(memberPage.getByRole('heading', { name: '小組消息', exact: true })).toBeVisible();
      await expect(memberPage.getByRole('link', { name: new RegExp(title) })).toBeVisible();
      await memberPage.getByRole('link', { name: new RegExp(title) }).click();
      await expect(memberPage).toHaveURL(new RegExp(`/app/group-news/${contentId}$`));
      await expect(memberPage.getByRole('heading', { name: title })).toBeVisible();
      await expect(memberPage.getByText('這則合成文章只屬於指定小組。')).toBeVisible();
    } finally {
      await memberContext.close();
    }

    await page.goto('/app/admin/groups');
    await expect(page.getByRole('link', { name: '協會 Logo，回到官網' })).toHaveAttribute('href', '/');
    await expect(page.getByRole('link', { name: '回到官網', exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: '協會 Logo，回到官網' }).click();
    await expect(page).toHaveURL(/\/$/);
    await page.goto(`/news/${contentId}`);
    await expect(page.getByRole('heading', { name: title })).toHaveCount(0);
  } finally {
    if (contentId) await mutate(adminApi, 'delete', `/api/v1/contents/${contentId}`);
    if (group) {
      const response = await adminApi.get(`/api/v1/groups/${group.id}`);
      if (response.ok()) {
        const current = await response.json() as Group;
        await mutate(adminApi, 'put', `/api/v1/groups/${current.id}`, {
          name: current.name, description: current.description, active: false, member_ids: [], version: current.version,
        });
      }
    }
    await adminApi.dispose();
  }
});

async function signIn(page: import('@playwright/test').Page, phone: string) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill(phone);
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}
