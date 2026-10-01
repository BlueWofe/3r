import { expect, test, type Page } from '@playwright/test';

type LoginRecord = {
  id: number;
  user_id: number;
  name: string;
  result: 'success' | 'failure';
  occurred_at: string;
  ip_address: string;
  user_agent: string;
  roles: { id: number; name: string; slug: string }[];
  categories: string[];
};

const roles = [
  { id: 9, name: '一般會員角色', slug: 'member' },
  { id: 10, name: '志工核心角色', slug: 'volunteer-core' },
  { id: 11, name: '系統管理角色', slug: 'system-admin' },
];

function record(id: number, name: string, role: LoginRecord['roles'][number], occurredAt = '2026-06-15T09:20:30+08:00'): LoginRecord {
  return {
    id, user_id: id + 1000, name, result: id % 2 ? 'success' : 'failure',
    occurred_at: occurredAt, ip_address: '192.0.2.20', user_agent: 'Synthetic Browser / Test',
    roles: [role], categories: role.slug === 'member' ? ['member'] : role.slug === 'system-admin' ? ['admin'] : ['volunteer'],
  };
}

async function mockAdmin(page: Page, mode: 'rows' | 'loading' | 'error' = 'rows') {
  let responseMode = mode;
  let releaseLoading: (() => void) | undefined;
  const requests: URL[] = [];
  await page.route('**/api/v1/**', route => route.fulfill({ json: {} }));
  await page.route('**/api/v1/auth/me', route => route.fulfill({ json: { user: {
    id: 1, name: '合成系統管理員', phone: '0900000000', roles: [{ id: 11, name: '系統管理角色', slug: 'system-admin' }],
    permissions: ['users.read.all'],
  } } }));
  await page.route('**/api/v1/login-records**', async route => {
    const url = new URL(route.request().url());
    requests.push(url);
    if (responseMode === 'loading') {
      await new Promise<void>(resolve => { releaseLoading = resolve; });
      return route.fulfill({ json: { data: [], meta: { total: 0, current_page: 1, per_page: 20, last_page: 1 }, role_options: roles } });
    }
    if (responseMode === 'error') return route.fulfill({ status: 500, json: { message: '登入紀錄暫時無法載入。' } });

    const category = url.searchParams.get('category') || 'member';
    const pageNumber = Number(url.searchParams.get('page') || 1);
    const roleId = url.searchParams.get('role_id');
    const from = url.searchParams.get('from');
    const to = url.searchParams.get('to');
    const role = category === 'admin' ? roles[2] : category === 'volunteer' ? roles[1] : roles[0];
    let rows: LoginRecord[] = category === 'admin'
      ? [record(301, '合成管理員紀錄', roles[2])]
      : category === 'volunteer'
        ? [record(201, '合成志工紀錄', roles[1])]
        : [
            ...Array.from({ length: 20 }, (_, index) => record(100 + index, `合成會員紀錄 ${index + 1}`, roles[0])),
            record(120, '合成篩選會員紀錄', roles[1]),
          ];
    if (roleId) rows = rows.filter(row => row.roles.some(item => item.id === Number(roleId)));
    if (from) rows = rows.filter(row => row.occurred_at.slice(0, 10) >= from);
    if (to) rows = rows.filter(row => row.occurred_at.slice(0, 10) <= to);
    const perPage = 20;
    const total = rows.length;
    rows = rows.slice((pageNumber - 1) * perPage, pageNumber * perPage);
    return route.fulfill({ json: {
      data: rows,
      meta: { total, current_page: pageNumber, per_page: perPage, last_page: Math.max(1, Math.ceil(total / perPage)) },
      role_options: [role, ...roles.filter(item => item.id !== role.id)],
    } });
  });
  return {
    requests,
    setMode(next: 'rows' | 'loading' | 'error') { responseMode = next; },
    releaseLoading() { releaseLoading?.(); },
  };
}

test('login records switch user categories, filter by role and date, and paginate', async ({ page }, testInfo) => {
  if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 800 });
  const mock = await mockAdmin(page);
  await page.goto('/app/admin/login-records');

  const tabs = page.getByRole('tablist', { name: '使用者分類' });
  const recordList = page.locator(testInfo.project.name === 'mobile' ? '.mobile-records' : '.desktop-records');
  await expect(page.getByRole('heading', { name: '登入記錄', exact: true })).toBeVisible();
  await expect(tabs.getByRole('tab')).toHaveCount(3);
  await expect(recordList.getByText('合成會員紀錄 1', { exact: true })).toBeVisible();
  await expect(recordList.getByText('合成會員紀錄 20', { exact: true })).toBeVisible();
  await expect(recordList.getByText('合成篩選會員紀錄', { exact: true })).toHaveCount(0);

  const pagination = page.getByRole('navigation', { name: '登入記錄分頁' });
  await pagination.getByRole('button', { name: '下一頁' }).click();
  await expect(recordList.getByText('合成篩選會員紀錄', { exact: true })).toBeVisible();
  await expect(recordList.getByText('合成會員紀錄 1', { exact: true })).toHaveCount(0);

  await tabs.getByRole('tab', { name: '志工使用者' }).click();
  await expect(recordList.getByText('合成志工紀錄', { exact: true })).toBeVisible();
  await expect(recordList.getByText('合成篩選會員紀錄', { exact: true })).toHaveCount(0);
  await expect(recordList.locator('[data-login-record-id="201"]')).toContainText('志工核心角色');
  await tabs.getByRole('tab', { name: '後台管理者' }).click();
  await expect(recordList.getByText('合成管理員紀錄', { exact: true })).toBeVisible();

  await tabs.getByRole('tab', { name: '一般使用者' }).click();
  await page.getByRole('combobox', { name: '角色', exact: true }).selectOption('10');
  await expect(recordList.getByText('合成篩選會員紀錄', { exact: true })).toBeVisible();
  await expect(recordList.getByText('合成會員紀錄 1', { exact: true })).toHaveCount(0);
  await page.getByRole('textbox', { name: '開始日期', exact: true }).fill('2026-06-01');
  await page.getByRole('textbox', { name: '結束日期', exact: true }).fill('2026-06-30');
  await page.getByRole('button', { name: '套用篩選', exact: true }).click();
  await expect.poll(() => mock.requests.at(-1)?.searchParams.get('to')).toBe('2026-06-30');
  expect(mock.requests.at(-1)?.searchParams.get('role_id')).toBe('10');
  expect(mock.requests.at(-1)?.searchParams.get('page')).toBe('1');
  await page.getByRole('button', { name: '清除篩選' }).click();
  await expect(page.getByRole('combobox', { name: '角色', exact: true })).toHaveValue('');
  await expect(page.getByRole('textbox', { name: '開始日期', exact: true })).toHaveValue('');
  expect(mock.requests.at(-1)?.searchParams.get('page')).toBe('1');

  const volunteerRow = recordList.locator('[data-login-record-id="201"]');
  await tabs.getByRole('tab', { name: '志工使用者' }).click();
  await expect(volunteerRow).toContainText('志工核心角色');
  await expect(volunteerRow).not.toContainText('volunteer-core');
  await expect(volunteerRow).not.toContainText('T09:20:30');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2)).toBeTruthy();
});

test('login records show loading, empty, and recoverable error states', async ({ page }) => {
  const mock = await mockAdmin(page, 'loading');
  await page.goto('/app/admin/login-records');
  await expect(page.getByText('載入登入記錄中…', { exact: true })).toBeVisible();
  mock.releaseLoading();
  await expect(page.getByText('這個分類目前沒有符合條件的登入記錄。', { exact: true })).toBeVisible();

  mock.setMode('error');
  await page.reload();
  await expect(page.getByRole('alert')).toContainText('登入紀錄暫時無法載入');
});
