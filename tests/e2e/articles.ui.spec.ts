import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, login, mutate, unique } from './helpers';

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
    await page.getByLabel('分類').fill('見證分享');
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
