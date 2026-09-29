import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

function taipeiLocalDateTimeOffset(daysAgo: number) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(Date.now() - daysAgo * 24 * 60 * 60_000));
  const part = (type: string) => parts.find(item => item.type === type)!.value;
  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

async function loginAsAdmin(page: Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼').fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}

test('admin formats, saves and edits an authored story that renders publicly with date and author', async ({ page }) => {
  const admin = await apiContext();
  let createdId: number | undefined;
  const slug = unique('e2e-story-ui').toLowerCase();
  const title = `E2E 見證文章 ${Date.now()}`;
  try {
    await login(admin, '0900000001');
    await loginAsAdmin(page);
    await page.goto('/app/admin/content');
    await expect(page.getByRole('heading', { name: '內容管理' })).toBeVisible();
    await page.getByRole('button', { name: '新增', exact: true }).click();

    await page.getByLabel('類型').selectOption('news');
    await page.getByLabel('標題', { exact: true }).fill(title);
    await page.getByLabel('網址代稱').fill(slug);
    await page.getByLabel('分類', { exact: true }).fill('見證分享');
    await page.getByLabel('作者').fill('E2E 見證作者');
    // Use a clearly historical date so this synthetic article cannot displace live newest stories.
    await page.getByLabel('發布時間').fill(taipeiLocalDateTimeOffset(3));
    await page.getByLabel('狀態').selectOption('published');
    await page.getByLabel('摘要').fill('桌機與手機圖文編輯器驗收內容');

    const editor = page.getByRole('textbox', { name: '本文' });
    await expect(editor).toBeVisible();
    await editor.click();
    await page.getByRole('button', { name: '標題二' }).click();
    await editor.pressSequentially('更新的見證標題');
    await editor.press('Enter');
    await page.getByRole('button', { name: '粗體' }).click();
    await editor.pressSequentially('以粗體呈現的格式文字');
    await editor.press('Enter');
    await page.getByRole('button', { name: '項目清單' }).click();
    await editor.pressSequentially('陪伴行動項目');
    await page.locator('.rich-toolbar input[type="file"]').setInputFiles({
      name: `${slug}.png`,
      mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', 'base64'),
    });
    const editorImage = editor.locator('img').first();
    await expect(editorImage).toBeVisible();
    await expect(editorImage).toHaveAttribute('src', /\/api\/v1\/files\/\d+\/download/);
    const editorImageSrc = await editorImage.getAttribute('src');
    const fileId = editorImageSrc?.match(/\/files\/(\d+)\/download/)?.[1];
    expect(fileId, 'toolbar upload should link the public file record').toBeTruthy();
    const preview = page.locator('details').filter({ hasText: '文章預覽' });
    await preview.locator('summary').click();
    await expect(preview.locator('img').first()).toBeVisible();

    const createResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && /\/api\/v1\/contents$/.test(new URL(response.url()).pathname),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.ok(), `article create returned ${createResponse.status()}`).toBeTruthy();
    createdId = (await createResponse.json()).id as number;
    expect(createdId).toBeTruthy();

    const articleRow = page.getByRole('row').filter({ hasText: title });
    await expect(articleRow).toBeVisible();
    await expect(page).toHaveURL(/section=testimony/);
    await expect(page.getByRole('button', { name: /見證分享/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: /最新消息/ }).click();
    await expect(page).toHaveURL(/section=news/);
    await expect(page.getByRole('row').filter({ hasText: title })).toHaveCount(0);
    await page.getByRole('button', { name: /見證分享/ }).click();
    await expect(page).toHaveURL(/section=testimony/);
    await expect(articleRow).toBeVisible();
    await articleRow.getByRole('button', { name: '編輯' }).click();
    const reopenedEditor = page.getByRole('textbox', { name: '本文' });
    await expect(reopenedEditor.locator('h2')).toContainText('更新的見證標題');
    await expect(reopenedEditor.locator('strong')).toContainText('以粗體呈現的格式文字');
    await expect(reopenedEditor.locator('li')).toContainText('陪伴行動項目');
    await expect(reopenedEditor.locator('img').first()).toHaveAttribute('src', new RegExp(`/api/v1/files/${fileId}/download`));
    await page.getByLabel('摘要').fill('更新後的摘要，保留原有標題、粗體與清單格式');
    const updateResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'PUT' && new URL(response.url()).pathname.endsWith(`/api/v1/contents/${createdId}`),
    );
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const updateResponse = await updateResponsePromise;
    expect(updateResponse.ok(), `article update returned ${updateResponse.status()}`).toBeTruthy();
    await expect(page.getByRole('row').filter({ hasText: title })).toBeVisible();

    await page.context().clearCookies();
    await page.goto(`/news/${createdId}`);
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.locator('.rich-article h2')).toContainText('更新的見證標題');
    await expect(page.locator('.rich-article strong')).toContainText('以粗體呈現的格式文字');
    await expect(page.locator('.rich-article li')).toContainText('陪伴行動項目');
    const publicImage = page.locator('.rich-article img').first();
    await expect(publicImage).toBeVisible();
    await expect(publicImage).toHaveAttribute('src', new RegExp(`/api/v1/files/${fileId}/download`));
    await expect.poll(() => publicImage.evaluate(image => ({ complete: (image as HTMLImageElement).complete, width: (image as HTMLImageElement).naturalWidth }))).toMatchObject({ complete: true, width: 1 });
    await expect(page.locator('article p.muted').filter({ hasText: 'E2E 見證作者' })).toContainText(/\d{4}年.+\d{1,2}:\d{2}/);

    await page.goto('/news');
    const publicNewsLink = page.getByRole('link', { name: new RegExp(title) });
    await expect(publicNewsLink).toBeVisible();
    await publicNewsLink.click();
    await expect(page).toHaveURL(new RegExp(`/news/${createdId}$`));
  } finally {
    try {
      if (!createdId) {
        try {
        const listing = await admin.get('/api/v1/contents');
        if (listing.ok()) {
          const own = (await listing.json()).data?.find((item: { slug: string }) => item.slug === slug);
          createdId = own?.id;
        }
        } catch {
          // Best effort lookup only; preserve the original browser assertion failure.
        }
      }
      if (createdId) {
        const deleted = await mutate(admin, 'delete', `/api/v1/contents/${createdId}`);
        expect([200, 204, 404]).toContain(deleted.status());
      }
    } finally {
      await admin.dispose();
    }
  }
});

test('content management sections filter latest news, testimony, and association pages', async ({ page }) => {
  const api = await apiContext();
  const suffix = unique('content-sections');
  const fixtures = [
    { section: 'news', title: `${suffix} 最新消息`, kind: 'news', category: '最新消息' },
    { section: 'testimony', title: `${suffix} 見證分享`, kind: 'news', category: '見證分享' },
    { section: 'pages', title: `${suffix} 協會頁面`, kind: 'page', category: '' },
  ] as const;
  const ids: number[] = [];
  try {
    await login(api, '0900000001');
    for (const fixture of fixtures) {
      const content = await json<{ id: number }>(await mutate(api, 'post', '/api/v1/contents', {
        kind: fixture.kind,
        title: fixture.title,
        slug: `${suffix}-${fixture.section}`.toLowerCase(),
        body: `合成內容：${fixture.title}`,
        body_format: 'text',
        summary: '僅供 E2E 驗收',
        category: fixture.category,
        status: 'draft',
        sort_order: 0,
        metadata: {},
      }));
      ids.push(content.id);
    }

    await loginAsAdmin(page);
    await page.goto('/app/admin/content?section=news');
    await expect(page.getByRole('heading', { name: '內容管理', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /最新消息/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('row').filter({ hasText: fixtures[0].title })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: fixtures[1].title })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: fixtures[2].title })).toHaveCount(0);

    await page.getByRole('button', { name: /見證分享/ }).click();
    await expect(page).toHaveURL(/section=testimony/);
    await expect(page.getByRole('row').filter({ hasText: fixtures[1].title })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: fixtures[0].title })).toHaveCount(0);

    await page.getByRole('button', { name: /協會頁面/ }).click();
    await expect(page).toHaveURL(/section=pages/);
    await expect(page.getByRole('row').filter({ hasText: fixtures[2].title })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: fixtures[1].title })).toHaveCount(0);
  } finally {
    for (const id of ids) {
      const deleted = await mutate(api, 'delete', `/api/v1/contents/${id}`);
      expect([200, 204, 404]).toContain(deleted.status());
    }
    await api.dispose();
  }
});
