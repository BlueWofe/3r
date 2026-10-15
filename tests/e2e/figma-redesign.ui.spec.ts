import { expect, test, type Page, type Route } from '@playwright/test';

test.use({ timezoneId: 'Asia/Taipei' });

const sessionFixture = (serviceDate = taipeiDate()) => ({
  id: 410,
  title: '生命教育班',
  class_name: '週三同行班',
  prison: '合成示範場域',
  prison_address: '合成地址，不對應真實監所',
  location: '合成教室 A',
  participant_count: 12,
  service_date: serviceDate,
  start_time: '14:00',
  end_time: '15:30',
  status: 'scheduled',
  version: 3,
  color: '#3d8768',
  assignments: [{
    id: 510,
    teacher_id: 22,
    status: 'assigned',
    attendance: null,
    teacher: { id: 22, name: '合成志工甲' },
  }],
  invitations: [],
  events: [],
});

const productFixture = {
  id: 31,
  kind: 'product',
  title: '合成愛心餅乾',
  slug: 'synthetic-cookie',
  summary: '專供介面驗收的虛構商品。',
  body: '不代表實際販售或交易。',
  status: 'published',
  category: '合成商品',
  metadata: {
    variants: [{ id: 'box-6', active: true, stock: 20, price: 100, options: ['六入'] }],
    ingredients: '合成配方',
    allergens: '無',
    net_weight: '合成規格',
    shelf_life: '合成期限',
    storage: '常溫',
    origin: '台灣',
    unit: '盒',
  },
};

const adminPermissions = [
  'schedule.read.own', 'schedule.read.all', 'schedule.update.own', 'schedule.update.all',
  'schedule.create.all', 'attendance.create.own', 'attendance.update.all', 'users.read.all',
  'users.create.all', 'users.update.all', 'roles.manage.all', 'content.read.all',
  'content.create.all', 'content.update.all', 'content.publish.all', 'orders.read.all',
  'orders.update.all', 'contacts.read.all', 'contacts.update.all', 'cases.read.all',
  'cases.create.all', 'cases.update.all', 'cases.export.all', 'meetings.read.all',
  'meetings.create.all', 'meetings.update.all', 'forms.read.all', 'forms.create.all',
  'forms.update.all', 'forms.export.all', 'resources.read.all', 'groups.manage.all',
  'prisons.manage.all', 'reports.read.all', 'settings.manage.all', 'donations.read.all',
];

const memberTeacherPermissions = [
  'schedule.read.own', 'schedule.update.own', 'attendance.create.own', 'forms.read.own',
  'donations.read.own', 'resources.read.own',
];

test('public story, article, food and guest order remain usable at desktop and phone widths', async ({ page }) => {
  await mockApi(page, 'guest');

  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: /認識我們/ }).first()).toBeVisible();
    await expectNoOverflow(page, `public home at ${width}px`);
  }

  await page.locator('a[href="/news"]:visible').first().click();
  await page.getByRole('link', { name: /合成消息：同行近況/ }).click();
  await expect(page.getByRole('heading', { name: '合成消息：同行近況' })).toBeVisible();
  await expect(page.getByText('這是可供 UI 測試的虛構文章內容。')).toBeVisible();

  await page.locator('a[href="/food"]:visible').first().click();
  await expect(page.getByRole('heading', { name: /愛心好食|好食/ })).toBeVisible();
  await page.getByRole('link', { name: /合成愛心餅乾/ }).click();
  await expect(page.getByRole('heading', { name: '合成愛心餅乾' })).toBeVisible();
  await page.getByLabel('數量').fill('2');
  await page.getByRole('button', { name: '加入購物車' }).click();
  await expect(page.getByRole('status')).toContainText('已加入購物車');
  await page.getByRole('link', { name: '前往購物車' }).click();

  await expect(page.getByRole('heading', { name: '購物車' })).toBeVisible();
  await page.getByLabel('收件／聯絡人姓名').fill('合成訪客');
  await page.getByLabel('聯絡電話').fill('0900000099');
  await page.getByLabel('收件地址').fill('台灣合成地址 1 號');
  await page.getByRole('button', { name: '確認並送出訂單' }).click();
  await expect(page.getByRole('heading', { name: '已收到您的訂單' })).toBeVisible();
  await expect(page.getByText('此頁未收取付款。')).toBeVisible();
  await expectNoOverflow(page, 'guest cart at 390px');
});

test('multi-role volunteer gets service navigation, inbox focus links and read-only session detail', async ({ page }) => {
  await mockApi(page, 'member-teacher');
  await page.setViewportSize({ width: 390, height: 844 });
  await loginWithSyntheticAccount(page, 'member-teacher');

  await expect(page.locator('nav[aria-label="管理工作台"]')).toHaveCount(0);
  await expect(page.getByRole('link', { name: /排程管理|人員與角色|管理工作台/ })).toHaveCount(0);
  const serviceMenu = page.getByRole('button', { name: /我的服務/ });
  await serviceMenu.click();
  await expect(serviceMenu).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('navigation', { name: '我的服務' }).getByRole('link', { name: '行事曆' })).toBeVisible();
  await serviceMenu.press('Escape');
  await expect(serviceMenu).toHaveAttribute('aria-expanded', 'false');
  await expect(serviceMenu).toBeFocused();

  await clientNavigate(page, '/app/invitations?tab=notifications');
  await page.getByRole('tab', { name: /站內通知/ }).click();
  await expect(page.locator('[data-testid="notification-tab-count-all"]')).toHaveText('2');
  await page.getByRole('tab', { name: /課程通知/ }).click();
  await expect(page.locator('[data-notification-id="701"]')).toBeVisible();
  await page.getByRole('button', { name: /課程異動：生命教育班/ }).click();
  await expect(page).toHaveURL(/\/app\/calendar\?session_id=410/);
  await page.locator('[data-calendar-session-id="410"]').click();
  const details = page.getByTestId('calendar-session-detail');
  await expect(details).toBeVisible();
  await expect(details).toContainText('合成志工甲');
  await expect(details).toContainText('合成教室 A');
  await expect(details.getByTestId('session-detail-edit')).toHaveCount(0);
  await expectNoOverflow(page, 'volunteer session detail at 390px');

  await details.getByTestId('self-check-in').click();
  await expect(page.getByTestId('self-attendance-dialog')).toBeVisible();
  await expect(page.getByText(/簽到照片（選填/)).toBeVisible();
  let attendanceBody = '';
  await page.route('**/api/v1/assignments/510/attendance', async route => {
    attendanceBody = route.request().postData() || '';
    await fulfill(route, { message: '簽到已記錄', attendance: { kind: 'check_in', photo_id: null } });
  });
  await page.getByTestId('self-attendance-submit').click();
  await expect.poll(() => attendanceBody).toContain('name="mode"');
  expect(attendanceBody).toContain('self');
  expect(attendanceBody).not.toContain('name="photo"');
  await expect(page.getByTestId('self-attendance-dialog')).toBeHidden();
});

test('admin cards, permission labels and form responses retain reviewable work without raw JSON', async ({ page }) => {
  await mockApi(page, 'admin');
  await page.setViewportSize({ width: 390, height: 900 });
  await loginWithSyntheticAccount(page, 'admin');

  await clientNavigate(page, '/app/admin/users');
  await expect(page.getByRole('heading', { name: '人員與角色' })).toBeVisible();
  await expect(page.getByText('合成志工甲')).toBeVisible();
  await expectNoOverflow(page, 'admin people list at 390px');

  await clientNavigate(page, '/app/admin/roles');
  await page.getByRole('button', { name: '設定權限' }).click();
  await expect(page.getByText('查看自己的課程')).toBeVisible();
  await expect(page.getByText('為自己的課程打卡')).toBeVisible();
  await expect(page.getByText('管理角色、權限及人員角色指派')).toBeVisible();
  await page.getByRole('button', { name: '全不選', exact: true }).first().click();
  await page.getByRole('button', { name: '儲存角色' }).click();
  await expect(page.getByText('此角色已有新版本')).toBeVisible();

  await clientNavigate(page, '/app/admin/forms');
  await expect(page.getByRole('heading', { name: '表單中心' })).toBeVisible();
  await page.getByRole('button', { name: /回覆與版本/ }).click();
  await expect(page.getByRole('heading', { name: '讀懂每一份回覆' })).toBeVisible();
  await page.getByRole('button', { name: '附件與欄位答案' }).click();
  await expect(page.getByText('服務日期')).toBeVisible();
  await expect(page.getByText('希望增加共同備課時間。')).toBeVisible();
  await expect(page.locator('pre')).toHaveCount(0);
  await expectNoOverflow(page, 'form response detail at 390px');
});

test('admin schedule conflict and server permission denial stay visible to the operator', async ({ page }) => {
  await mockApi(page, 'admin');
  await loginWithSyntheticAccount(page, 'admin');
  await clientNavigate(page, '/app/admin/schedule');
  await page.getByRole('button', { name: '管理場次' }).click();
  await page.getByLabel('異動原因／備註').fill('合成驗收的衝突處理');
  await page.getByRole('button', { name: '停課' }).click();
  await expect(page.getByRole('alert')).toContainText('課程已有新異動');

  await clientNavigate(page, '/app/admin/contact-inquiries');
  await page.getByRole('button', { name: '查看' }).click();
  await page.getByLabel('同工備註').fill('合成回覆，未送出外部聯絡。');
  await page.getByRole('button', { name: '儲存處理狀態' }).click();
  await expect(page.getByText('目前沒有處理這筆訊息的權限')).toBeVisible();
});

test('the 38 Figma route screens still resolve for their intended audience', async ({ page }) => {
  test.setTimeout(120_000);
  await mockApi(page, 'admin');
  await page.setViewportSize({ width: 390, height: 844 });
  await loginWithSyntheticAccount(page, 'admin');
  const routes = [
    '/', '/about', '/news', '/news/301', '/search', '/food', '/food/31', '/cart',
    '/donate', '/contact', '/login', '/change-password',
    '/app', '/app/calendar', '/app/resources', '/app/forms', '/app/forms/77',
    '/app/profile', '/app/changes', '/app/invitations?tab=notifications',
    '/app/group-news', '/app/group-news/301',
    '/app/admin/schedule', '/app/admin/classes', '/app/admin/prisons', '/app/admin/cases',
    '/app/admin/reports', '/app/admin/content?section=news', '/app/admin/products',
    '/app/admin/orders', '/app/admin/contact-inquiries', '/app/admin/meetings',
    '/app/admin/forms', '/app/admin/users', '/app/admin/roles',
    '/app/admin/login-records', '/app/admin/groups', '/app/admin/settings',
  ];
  expect(routes).toHaveLength(38);

  for (const path of routes) {
    await clientNavigate(page, path);
    if (path === '/change-password') {
      await expect(page).not.toHaveURL(/\/change-password/);
      continue;
    }
    await expect(page.locator('main, .workspace').first(), `${path} should render a page shell`).toBeVisible();
    await expectNoOverflow(page, `${path} at 390px`);
  }
});

async function mockApi(page: Page, persona: 'guest' | 'member-teacher' | 'admin') {
  await page.route('**/api/v1/**', async (route: Route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1', '');
    const method = request.method();
    if (path === '/auth/csrf') return fulfill(route, { csrf_token: 'synthetic-csrf-token' });
    if (path === '/auth/login' && method === 'POST') {
      return fulfill(route, { user: userFor(persona) });
    }
    if (path === '/auth/me') {
      if (persona === 'guest') return fulfill(route, { message: 'Unauthenticated.' }, 401);
      return fulfill(route, { user: userFor(persona) });
    }
    if (path === '/public/news' && method === 'GET') return fulfill(route, { data: [newsFixture()] });
    if (path === '/public/news/301') return fulfill(route, { data: newsFixture() });
    if (path === '/public/pages/about') return fulfill(route, { data: { title: '認識協會', body: '合成介紹' } });
    if (path === '/public/products' && method === 'GET') return fulfill(route, { data: [productFixture], categories: ['合成商品'] });
    if (path === '/public/products/31') return fulfill(route, { data: productFixture });
    if (path === '/public/products/31/quote') return fulfill(route, { product_id: 31, variant_id: 'box-6', unit_price: 100, total: 200, currency: 'TWD' });
    if (path === '/public/search') return fulfill(route, { data: [] });
    if (path === '/public/shipping-settings') return fulfill(route, { shipping_enabled: true, pickup_enabled: true, flat_fee: 60, free_shipping_threshold: null });
    if (path === '/public/orders/quote' && method === 'POST') return fulfill(route, {
      items: [{ product_id: 31, variant_id: 'box-6', title: productFixture.title, options: ['六入'], unit_price: 100, total: 200 }],
      subtotal: 200, shipping_fee: 60, total: 260, total_cents: 26000,
    });
    if (path === '/public/orders' && method === 'POST') return fulfill(route, { order_number: 'SYNTH-2601', total: 260 });
    if (path === '/notifications' && method === 'GET') return fulfill(route, {
      data: [
        { id: 701, owner_id: 22, title: '課程異動：生命教育班', message: '請查看合成課程安排。', category: 'course', read: false, url: '/app/calendar?session_id=410', created_at: '2026-10-15T08:00:00+08:00' },
        { id: 702, owner_id: 22, title: '訂單更新', message: '這是合成商品通知。', category: 'product', read: false, url: '/app/admin/orders?order_id=1', created_at: '2026-10-15T08:10:00+08:00' },
      ], unread_count: 2,
    });
    if (/\/notifications\/\d+\/read$/.test(path) || path === '/notifications/read-all') return fulfill(route, { message: '已更新', unread_count: 1 });
    if (path === '/sessions' && method === 'GET') return fulfill(route, { data: [sessionFixture()] });
    if (path === '/sessions/410' && method === 'GET') return fulfill(route, sessionFixture());
    if (path === '/meetings') return fulfill(route, { data: [] });
    if (path === '/teachers' || path === '/prisons/options') return fulfill(route, { data: [{ id: 22, name: '合成志工甲' }] });
    if (path === '/invitations') return fulfill(route, { data: [] });
    if (path === '/donations?own=1') return fulfill(route, { data: [] });
    if (path === '/integrations/line') return fulfill(route, { bound: false, subscribed: false });
    if (path === '/users') return fulfill(route, { data: [{ id: 22, name: '合成志工甲', phone: '0900000098', active: true, roles: [{ id: 3, name: '志工老師' }, { id: 4, name: '小組同工' }] }] });
    if (path === '/roles' && method === 'GET') return fulfill(route, { data: [{ id: 3, name: '志工老師', slug: 'synthetic-teacher', active: true, permissions: ['schedule.read.own', 'attendance.create.own'] }] });
    if (path === '/permissions') return fulfill(route, { data: adminPermissions });
    if (path === '/roles/3' && method === 'PUT') return fulfill(route, { message: '此角色已有新版本，請重新載入後再儲存。' }, 409);
    if (path === '/forms' && method === 'GET') return fulfill(route, { data: [{ id: 77, title: '合成服務回饋', description: '合成表單', status: 'published', version: 2, fields: [{ key: 'service_date', label: '服務日期', type: 'date' }, { key: 'reflection', label: '心得', type: 'textarea' }], snapshots: [{ version: 2, fields: [{ key: 'service_date', label: '服務日期', type: 'date' }, { key: 'reflection', label: '心得', type: 'textarea' }] }] }] });
    if (path === '/role-options') return fulfill(route, { data: [{ id: 3, name: '志工老師' }] });
    if (path === '/forms/77/responses') return fulfill(route, { data: [{ id: 1, owner_id: 22, version: 2, answers: { service_date: '2026-10-15', reflection: '希望增加共同備課時間。' }, created_at: '2026-10-15T15:20:00+08:00' }] });
    if (path.startsWith('/sessions/410') && method === 'PUT') return fulfill(route, { message: '這筆課程已有新異動，請重新載入。' }, 409);
    if (path === '/contact-inquiries' && method === 'GET') return fulfill(route, { data: [{ id: 61, name: '合成聯絡人', category: '其他', message: '合成詢問內容', status: 'new', created_at: '2026-10-15T09:00:00+08:00' }] });
    if (path === '/contact-inquiries/61' && ['PUT', 'PATCH'].includes(method)) return fulfill(route, { message: '目前沒有處理這筆訊息的權限。' }, 403);
    if (path === '/reports') return fulfill(route, { data: [], summary: {} });
    if (path === '/prisons/options' || path === '/groups/options') return fulfill(route, { data: [] });
    if (path.endsWith('/responses') && method === 'GET') return fulfill(route, { data: [] });
    if (method === 'GET') return fulfill(route, { data: [] });
    return fulfill(route, { message: '合成 UI 測試未設定此寫入路由。' }, 403);
  });
}

function userFor(persona: 'guest' | 'member-teacher' | 'admin') {
  return {
    id: 22,
    name: '合成志工甲',
    phone: '0900000098',
    must_change_password: false,
    roles: persona === 'admin' ? [{ id: 1, name: '系統管理員', slug: 'system-admin' }] : [{ id: 3, name: '志工老師', slug: 'synthetic-teacher' }, { id: 4, name: '一般會員', slug: 'member' }],
    permissions: persona === 'admin' ? ['*'] : persona === 'member-teacher' ? memberTeacherPermissions : [],
  };
}

function newsFixture() {
  return { id: 301, title: '合成消息：同行近況', article_type: 'news', category: '合成分類', summary: '合成摘要', body: '這是可供 UI 測試的虛構文章內容。', body_html: '<p>這是可供 UI 測試的虛構文章內容。</p>', author_name: '合成編輯', published_at: '2026-10-15T09:00:00+08:00', status: 'published' };
}

async function loginWithSyntheticAccount(page: Page, persona: 'member-teacher' | 'admin') {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000098');
  await page.getByLabel('密碼', { exact: true }).fill('synthetic-mock-only');
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
}

async function clientNavigate(page: Page, path: string) {
  await page.evaluate(async target => {
    const nuxt = (window as any).$nuxt;
    if (!nuxt?.$router) throw new Error('Nuxt client router is not available.');
    await nuxt.$router.push(target);
  }, path);
  await expect(page).toHaveURL(new RegExp(path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
}

async function fulfill(route: Route, data: unknown, status = 200) {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
}

function taipeiDate() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

async function expectNoOverflow(page: Page, context: string) {
  const widths = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(widths.document, widths.body), context).toBeLessThanOrEqual(widths.viewport + 2);
}
