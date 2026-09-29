import { expect, test } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

test('published organization metadata is public while draft structure stays private', async () => {
  const admin = await apiContext();
  const createdIds: number[] = [];
  try {
    await login(admin, '0900000001');
    const suffix = unique('org-structure').toLowerCase();
    const levels = ['理事會', '執行團隊'];
    const departments = [{ name: '關懷服務', description: '公開的合成部門說明' }];
    const team = [{ name: '驗收同工', role: '合成職務', bio: '公開的合成介紹' }];
    const metadata = {
      organization_levels: levels,
      departments,
      team,
      unrelated_setting: { keep: 'preserved' },
    };

    const published = await json<{ id: number; slug: string }>(await mutate(admin, 'post', '/api/v1/contents', {
      kind: 'page', title: `協會架構 ${suffix}`, slug: `${suffix}-public`,
      body: '公開的組織架構驗收本文。', body_format: 'text', status: 'published',
      metadata,
    }));
    createdIds.push(published.id);
    const draft = await json<{ id: number; slug: string }>(await mutate(admin, 'post', '/api/v1/contents', {
      kind: 'page', title: `未發布架構 ${suffix}`, slug: `${suffix}-draft`,
      body: '此內容只供管理員檢查，不應公開。', body_format: 'text', status: 'draft',
      metadata: { organization_levels: ['private-sentinel'], team: [], private_note: 'draft-only' },
    }));
    createdIds.push(draft.id);

    const publicPage = await json<{ data: { metadata: typeof metadata; body: string } }>(
      await admin.get(`/api/v1/public/pages/${published.slug}`),
    );
    expect(publicPage.data.metadata).toEqual(metadata);
    expect(publicPage.data.body).toContain('公開的組織架構');
    expect((await admin.get(`/api/v1/public/pages/${draft.slug}`)).status()).toBe(404);
    const publicPages = await json<{ data: { slug: string; metadata?: Record<string, unknown> }[] }>(
      await admin.get('/api/v1/public/pages'),
    );
    expect(publicPages.data.some(page => page.slug === published.slug)).toBeTruthy();
    expect(publicPages.data.some(page => page.slug === draft.slug)).toBeFalsy();
    expect(JSON.stringify(publicPages.data)).not.toContain('draft-only');
    expect(JSON.stringify(publicPages.data)).not.toContain('private-sentinel');
  } finally {
    for (const id of createdIds.reverse()) await mutate(admin, 'delete', `/api/v1/contents/${id}`);
    await admin.dispose();
  }
});
