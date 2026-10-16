import { expect, test } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

type Group = { id: number; name: string; description: string; active: boolean; version: number; member_ids: number[] };
type Article = { id: number; title: string; slug: string; version: number; status: string; visibility?: string; group_ids?: number[]; article_type?: string };

test('group articles stay private across public APIs and broadcast once to the deduplicated active membership', async () => {
  const admin = await apiContext();
  const member = await apiContext();
  const outsider = await apiContext();
  const contentIds: number[] = [];
  let groups: Group[] = [];
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const memberId = users.data.find(user => user.phone === '0900000003')!.id;
    const outsiderId = users.data.find(user => user.phone === '0900000004')!.id;
    for (const suffix of ['甲', '乙']) {
      groups.push(await json<Group>(await mutate(admin, 'post', '/api/v1/groups', {
        name: unique(`群消息測試${suffix}`), description: '合成驗收資料', active: true,
        member_ids: [memberId],
      })));
    }
    const tag = unique('e2e-group-post').toLowerCase();
    const create = async (overrides: Record<string, unknown>) => {
      const article = await json<Article>(await mutate(admin, 'post', '/api/v1/contents', {
        kind: 'news', title: `群內消息 ${tag}-${contentIds.length}`, slug: `${tag}-${contentIds.length}`,
        category: 'E2E 小組分類', summary: '不可進入公開站的合成消息', body_format: 'text', body: '小組限定內容',
        author_name: 'E2E 編輯', status: 'published', published_at: null,
        ...overrides,
      }));
      contentIds.push(article.id);
      return article;
    };
    const privatePost = await create({ visibility: 'groups', group_ids: groups.map(group => group.id) });
    const publicPost = await create({ visibility: 'public', article_type: 'testimony', category: 'E2E 公開分類' });
    const draft = await create({ visibility: 'groups', group_ids: groups.map(group => group.id), status: 'draft' });

    // Public endpoints must exclude group-only content even for an authenticated manager.
    for (const path of [
      '/api/v1/public/news?limit=100',
      `/api/v1/public/news/${privatePost.id}`,
      `/api/v1/public/search?q=${encodeURIComponent(tag)}`,
    ]) {
      const response = await admin.get(path);
      if (path.includes('/news/') && !path.includes('?')) expect(response.status()).toBe(404);
      else {
        expect(response.ok()).toBeTruthy();
        expect(JSON.stringify(await response.json())).not.toContain(privatePost.title);
      }
    }
    const categorized = await json<{ data: Article[] }>(await admin.get('/api/v1/public/news?article_type=testimony&category=E2E%20%E5%85%AC%E9%96%8B%E5%88%86%E9%A1%9E&limit=100'));
    expect(categorized.data.some(article => article.id === publicPost.id)).toBeTruthy();
    expect(categorized.data.some(article => article.id === privatePost.id)).toBeFalsy();

    await login(member, '0900000003');
    await login(outsider, '0900000004');
    const inbox = await json<{ data: Article[] }>(await member.get('/api/v1/group-news'));
    expect(inbox.data.some(article => article.id === privatePost.id)).toBeTruthy();
    expect(inbox.data.some(article => article.id === draft.id)).toBeFalsy();
    expect((await outsider.get(`/api/v1/group-news/${privatePost.id}`)).status()).toBe(404);
    expect((await member.get(`/api/v1/group-news/${privatePost.id}`)).status()).toBe(200);

    const broadcastPath = `/api/v1/contents/${privatePost.id}/broadcast`;
    const sent = await json<{ id: number; content_id: number; version: number; recipient_count: number; duplicate: boolean; line_mode: string }>(
      await mutate(admin, 'post', broadcastPath, { version: privatePost.version }),
    );
    expect(sent).toMatchObject({ content_id: privatePost.id, version: privatePost.version, recipient_count: 1, duplicate: false, line_mode: 'mock' });
    const repeated = await json<typeof sent>(await mutate(admin, 'post', broadcastPath, { version: privatePost.version }));
    expect(repeated).toMatchObject({ id: sent.id, recipient_count: 1, duplicate: true });
    const history = await json<{ data: { id: number; version: number }[] }>(await admin.get(`/api/v1/contents/${privatePost.id}/broadcasts`));
    expect(history.data.filter(row => row.version === privatePost.version)).toHaveLength(1);

    // Authorization is evaluated from the current membership, not the saved notification/post audience.
    const latestGroup = await json<Group>(await admin.get(`/api/v1/groups/${groups[0].id}`));
    groups[0] = await json<Group>(await mutate(admin, 'put', `/api/v1/groups/${latestGroup.id}`, {
      name: latestGroup.name, description: latestGroup.description, active: true,
      member_ids: [], version: latestGroup.version,
    }));
    expect((await member.get(`/api/v1/group-news/${privatePost.id}`)).status()).toBe(200);
    const deactivated = await json<Group>(await admin.get(`/api/v1/groups/${groups[1].id}`));
    groups[1] = await json<Group>(await mutate(admin, 'put', `/api/v1/groups/${deactivated.id}`, {
      name: deactivated.name, description: deactivated.description, active: false,
      member_ids: [memberId], version: deactivated.version,
    }));
    expect((await member.get(`/api/v1/group-news/${privatePost.id}`)).status()).toBe(404);
  } finally {
    for (const id of contentIds.reverse()) {
      const response = await mutate(admin, 'delete', `/api/v1/contents/${id}`);
      expect([200, 204, 404]).toContain(response.status());
    }
    for (const group of groups) {
      const response = await admin.get(`/api/v1/groups/${group.id}`);
      if (response.ok()) {
        const current = await response.json() as Group;
        await mutate(admin, 'put', `/api/v1/groups/${current.id}`, {
          name: current.name, description: current.description, active: false, member_ids: [], version: current.version,
        });
      }
    }
    await Promise.all([admin.dispose(), member.dispose(), outsider.dispose()]);
  }
});

test('group articles reject stale edits, private images, and broadcasts to an empty audience', async () => {
  const admin = await apiContext();
  let group: Group | undefined;
  let article: Article | undefined;
  try {
    await login(admin, '0900000001');
    group = await json<Group>(await mutate(admin, 'post', '/api/v1/groups', {
      name: unique('空收件群組'), active: true, member_ids: [],
    }));
    article = await json<Article>(await mutate(admin, 'post', '/api/v1/contents', {
      kind: 'news', title: unique('無收件人群消息'), slug: unique('no-recipient').toLowerCase(),
      category: '', article_type: 'news', visibility: 'groups', group_ids: [group.id], status: 'published', published_at: null,
      body_format: 'html', body: '<p>合成私人文字</p>',
    }));
    const stale = await mutate(admin, 'put', `/api/v1/contents/${article.id}`, {
      kind: 'news', title: '舊版覆寫', slug: article.slug, category: '', article_type: 'news',
      visibility: 'groups', group_ids: [group.id], status: 'published', published_at: null,
      body_format: 'html', body: '<p>新內容</p>', version: article.version + 99,
    });
    expect(stale.status()).toBe(409);
    const forbiddenImage = await mutate(admin, 'put', `/api/v1/contents/${article.id}`, {
      kind: 'news', title: article.title, slug: article.slug, category: '', article_type: 'news',
      visibility: 'groups', group_ids: [group.id], status: 'published', published_at: null,
      body_format: 'html', body: '<p>含圖片</p><img src="/files/1">', version: article.version,
    });
    expect(forbiddenImage.status()).toBe(422);
    const broadcast = await mutate(admin, 'post', `/api/v1/contents/${article.id}/broadcast`, { version: article.version });
    expect(broadcast.status()).toBe(422);
  } finally {
    if (article) await mutate(admin, 'delete', `/api/v1/contents/${article.id}`);
    if (group) {
      const response = await admin.get(`/api/v1/groups/${group.id}`);
      if (response.ok()) {
        const current = await response.json() as Group;
        await mutate(admin, 'put', `/api/v1/groups/${group.id}`, { ...current, active: false, member_ids: [], version: current.version });
      }
    }
    await admin.dispose();
  }
});
