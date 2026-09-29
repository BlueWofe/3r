import { expect, test, type Page } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

const adminPages = [
  ['/app/admin/users', '人員與角色'],
  ['/app/admin/products', '產品管理'],
  ['/app/admin/classes', '班別管理'],
  ['/app/admin/content', '內容管理'],
  ['/app/admin/schedule', '排程管理'],
  ['/app/admin/reports', '服務報表'],
] as const;

test('admin tables and the two workspace menus fit 320px and 390px viewports', async ({ page }) => {
  await loginAs(page, '0900000001');

  for (const width of [320, 390]) {
    await page.setViewportSize({ width, height: 844 });
    for (const [path, heading] of adminPages) {
      await page.goto(path);
      await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
      await expectNoHorizontalOverflow(page, `admin ${path} at ${width}px`);
    }

    await page.goto('/app/admin/users');
    const serviceMenu = page.getByRole('button', { name: '我的服務', exact: true });
    const managementMenu = page.getByRole('button', { name: '管理工作台', exact: true });
    await expect(serviceMenu).toBeVisible();
    await expect(managementMenu).toBeVisible();
    await serviceMenu.click();
    await expect(serviceMenu).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('link', { name: '今日行程', exact: true })).toBeVisible();
    await managementMenu.click();
    await expect(serviceMenu).toHaveAttribute('aria-expanded', 'false');
    await expect(managementMenu).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('link', { name: '排程管理', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: '人員與角色', exact: true })).toBeVisible();
    await expectNoHorizontalOverflow(page, `workspace menu at ${width}px`);
  }
});

test('volunteer can submit leave from the calendar at 320px and 390px', async ({ page }) => {
  const admin = await apiContext();
  const created: { title: string; serviceDate: string; assignmentId: number }[] = [];
  try {
    await login(admin, '0900000001');
    const { data: users } = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const teacher = users.find(user => user.phone === '0900000002');
    expect(teacher, 'synthetic volunteer account exists').toBeTruthy();
    const baseDate = nextTaipeiDate();

    for (const [index, width] of [320, 390].entries()) {
      const title = unique(`行動請假${width}`);
      const serviceDate = addDays(baseDate, index);
      const session = await json<{
        version: number;
        assignments: { id: number; teacher_id: number; status: string }[];
      }>(await mutate(admin, 'post', '/api/v1/sessions', {
        title,
        prison: '合成驗收場域',
        location: '行動驗收教室',
        participant_count: 0,
        service_date: serviceDate,
        start_time: '23:00',
        end_time: '23:30',
        teacher_ids: [teacher!.id],
        override_conflict: true,
        reason: '隔離的行動 UX 合成課程',
      }));
      const assignment = session.assignments.find(item => item.teacher_id === teacher!.id);
      expect(assignment).toBeTruthy();
      created.push({ title, serviceDate, assignmentId: assignment!.id });
    }

    await loginAs(page, '0900000002');
    await page.goto('/app/calendar');
    for (const [index, fixture] of created.entries()) {
      const width = index === 0 ? 320 : 390;
      await page.setViewportSize({ width, height: 844 });
      await page.goto('/app/calendar');
      await moveCalendarToMonth(page, fixture.serviceDate);
      const event = page.getByRole('button', { name: new RegExp(fixture.title) });
      await expect(event).toBeVisible();
      const eventHeight = await event.evaluate(element => element.getBoundingClientRect().height);
      expect(eventHeight, `calendar action target at ${width}px`).toBeGreaterThanOrEqual(40);
      await expectNoHorizontalOverflow(page, `calendar at ${width}px`);

      await event.click();
      await expect(page.getByRole('heading', { name: fixture.title, exact: true })).toBeVisible();
      const reason = page.getByPlaceholder('請說明異動原因');
      await expect(reason).toBeVisible();
      await reason.fill(`行動寬度 ${width}px 請假驗收`);
      const response = page.waitForResponse(result =>
        result.url().endsWith(`/assignments/${fixture.assignmentId}/leave`) &&
        result.request().method() === 'POST',
      );
      await page.getByRole('button', { name: '請假', exact: true }).click();
      expect((await response).status(), `leave submitted at ${width}px`).toBe(200);

      const { data: sessions } = await json<{ data: { title: string; assignments: { id: number; status: string }[] }[] }>(
        await admin.get(`/api/v1/sessions?from=${fixture.serviceDate}&to=${fixture.serviceDate}`),
      );
      const updated = sessions.find(item => item.title === fixture.title);
      expect(updated?.assignments.find(item => item.id === fixture.assignmentId)?.status).toBe('leave');
    }
  } finally {
    await admin.dispose();
  }
});

async function loginAs(page: Page, phone: string) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill(phone);
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}

async function expectNoHorizontalOverflow(page: Page, label: string) {
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(dimensions.document, dimensions.body), label).toBeLessThanOrEqual(dimensions.viewport + 2);
}

function nextTaipeiDate() {
  const date = new Date();
  date.setDate(date.getDate() + 1);
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  const year = parts.find(part => part.type === 'year')!.value;
  const month = parts.find(part => part.type === 'month')!.value;
  const day = parts.find(part => part.type === 'day')!.value;
  return `${year}-${month}-${day}`;
}

function addDays(date: string, days: number) {
  const value = new Date(`${date}T12:00:00+08:00`);
  value.setDate(value.getDate() + days);
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(value);
}

async function moveCalendarToMonth(page: Page, serviceDate: string) {
  const [year, month] = serviceDate.split('-').map(Number);
  const monthLabel = `${year} 年 ${month} 月`;
  const label = page.locator('.workhead .muted').first();
  if (!(await label.textContent())?.includes(monthLabel)) {
    await page.getByRole('button', { name: '→', exact: true }).click();
  }
  await expect(label).toContainText(monthLabel);
}
