import { test, expect } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

type Article = {
  id: number;
  slug: string;
  title: string;
  status: string;
  category: string;
  author_name: string;
  published_at: string | null;
  body_format: 'text' | 'html';
  body_html?: string;
};

function publishedAtOffset(minutesAgo: number) {
  return new Date(Date.now() - minutesAgo * 60_000).toISOString();
}

test('public latest news sorts by publication time and id, sanitizes HTML, and excludes drafts/future posts', async () => {
  const admin = await apiContext();
  const createdIds: number[] = [];
  try {
    await login(admin, '0900000001');
    const suffix = unique('e2e-story').toLowerCase();
    const createArticle = async (input: Record<string, unknown>) => {
      const article = await json<Article>(await mutate(admin, 'post', '/api/v1/contents', input));
      createdIds.push(article.id);
      return article;
    };
    const base = {
      kind: 'news',
      category: '最新消息',
      summary: 'E2E 合成文章',
      body_format: 'html',
      body: '<h2>更新進度</h2><p><strong>格式文字</strong>與一般段落。</p><ul><li>清單項目</li></ul><p onclick="alert(1)">移除事件屬性</p><script>alert(2)</script><a href="javascript:alert(3)">危險連結</a>',
      status: 'published',
    };
    const older = await createArticle({
      ...base, title: `舊消息 ${suffix}`, slug: `${suffix}-older`, author_name: 'E2E 編輯甲',
      published_at: publishedAtOffset(180),
    });
    const tiedEarlierId = await createArticle({
      ...base, title: `同時發布甲 ${suffix}`, slug: `${suffix}-tie-a`, author_name: 'E2E 編輯乙',
      published_at: publishedAtOffset(60),
    });
    const tiedLaterId = await createArticle({
      ...base, title: `同時發布乙 ${suffix}`, slug: `${suffix}-tie-b`, author_name: 'E2E 編輯丙', category: '見證分享',
      published_at: tiedEarlierId.published_at,
    });
    const recent = await createArticle({
      ...base, title: `最新消息 ${suffix}`, slug: `${suffix}-recent`, author_name: 'E2E 編輯丁',
      published_at: publishedAtOffset(30),
    });
    const defaultPublication = await createArticle({
      ...base, title: `立即發布 ${suffix}`, slug: `${suffix}-immediate`, author_name: 'E2E 編輯戊',
      published_at: null,
    });
    expect(defaultPublication.published_at).toBeTruthy();

    // Ordinary edits must retain the first publication timestamp when the editor submits null.
    const preservedTimestamp = defaultPublication.published_at;
    const edited = await json<Article>(await mutate(admin, 'put', `/api/v1/contents/${defaultPublication.id}`, {
      ...base, title: `${defaultPublication.title} 已修訂`, slug: defaultPublication.slug,
      author_name: defaultPublication.author_name, published_at: null,
      body: '<h2>修訂標題</h2><p><em>保留格式</em></p>',
    }));
    expect(edited.published_at).toBe(preservedTimestamp);

    const draft = await createArticle({
      ...base, title: `草稿不得公開 ${suffix}`, slug: `${suffix}-draft`, status: 'draft',
      author_name: 'E2E 草稿作者', published_at: publishedAtOffset(1),
    });
    const future = await createArticle({
      ...base, title: `未來文章不得公開 ${suffix}`, slug: `${suffix}-future`,
      author_name: 'E2E 未來作者', published_at: new Date(Date.now() + 24 * 60 * 60_000).toISOString(),
    });

    const latest = await json<{ data: Article[] }>(await admin.get('/api/v1/public/news?limit=3'));
    expect(latest.data).toHaveLength(3);
    const chronological = [...latest.data].sort((left, right) => {
      const timeOrder = Date.parse(right.published_at!) - Date.parse(left.published_at!);
      return timeOrder || right.id - left.id;
    });
    expect(latest.data.map(article => article.id)).toEqual(chronological.map(article => article.id));
    expect(latest.data.some(article => article.id === draft.id || article.id === future.id)).toBeFalsy();
    expect(latest.data[0].published_at).toBeTruthy();
    expect(latest.data.every(article => article.category && article.author_name)).toBeTruthy();
    if (latest.data.some(article => article.id === tiedEarlierId.id) && latest.data.some(article => article.id === tiedLaterId.id)) {
      expect(latest.data.findIndex(article => article.id === tiedLaterId.id)).toBeLessThan(latest.data.findIndex(article => article.id === tiedEarlierId.id));
    }

    const detail = await json<{ data: Article }>(await admin.get(`/api/v1/public/news/${recent.id}`));
    expect(detail.data).toMatchObject({ author_name: 'E2E 編輯丁', category: '最新消息', body_format: 'html' });
    expect(detail.data.body_html).toContain('<h2>更新進度</h2>');
    expect(detail.data.body_html).toContain('<strong>格式文字</strong>');
    expect(detail.data.body_html).toContain('<ul>');
    expect(detail.data.body_html).not.toMatch(/<script|onclick\s*=|href=["']javascript:/i);

    for (const hidden of [draft, future]) {
      expect((await admin.get(`/api/v1/public/news/${hidden.id}`)).status()).toBe(404);
    }
    const search = await json<{ data: Article[] }>(await admin.get(`/api/v1/public/search?q=${encodeURIComponent(suffix)}`));
    expect(search.data.some(article => article.id === draft.id || article.id === future.id)).toBeFalsy();
    expect(search.data.some(article => article.id === recent.id)).toBeTruthy();
  } finally {
    // Keep the UAT homepage timeline clean even when an assertion fails.
    const removals = await Promise.all(createdIds.reverse().map(id => mutate(admin, 'delete', `/api/v1/contents/${id}`)));
    for (const response of removals) expect([200, 204, 404]).toContain(response.status());
    await admin.dispose();
  }
});
