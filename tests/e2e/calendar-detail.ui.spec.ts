import { test, expect, type Page } from '@playwright/test';

test.use({ timezoneId: 'Asia/Taipei' });
const now = '2026-10-02T21:30:00+08:00';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
function course(id: number, teacherId = 1, photo = false, date = '2026-10-02') {
  return {
    id, title: `合成詳情課程 ${id}`, class_name: '合成生命更新班', prison: '合成監所', prison_id: 8,
    prison_address: '合成地址八號', location: '合成教室二樓', participant_count: 12,
    service_date: date, start_time: '09:00', end_time: '10:00', status: 'scheduled', version: 1,
    color: '#3d8768', assignments: [{ id: id * 10, teacher_id: teacherId,
      teacher: { id: teacherId, name: teacherId === 1 ? '合成本人' : '合成其他教師' }, status: 'assigned',
      attendance: photo ? { kind: 'check_in', present: true, at: now, service_date: date, photo_id: id + 900 } : null as any,
    }], invitations: [], events: [],
  };
}
async function workspace(page: Page, courses: ReturnType<typeof course>[], admin = false, attendancePermission = true, readAll = admin) {
  const writes: { path: string; body: any }[] = [], files: number[] = [];
  await page.route('**/api/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/v1', '');
    if (path === '/auth/me') return route.fulfill({ json: { user: {
      id: 1, name: '合成本人', phone: '0900000001', must_change_password: false,
      roles: [{ id: 1, slug: 'teacher', name: '合成教師' }],
      permissions: ['schedule.read.own', 'schedule.update.own', ...(attendancePermission ? ['attendance.create.own'] : []),
        ...(readAll ? ['schedule.read.all'] : []), ...(admin ? ['schedule.update.all', 'attendance.update.all'] : [])],
    } } });
    if (path === '/auth/csrf') return route.fulfill({ json: { csrf_token: 'mock-csrf' } });
    if (path === '/notifications') return route.fulfill({ json: { data: [], unread_count: 0 } });
    if (path === '/teachers') return route.fulfill({ json: { data: [{ id: 1, name: '合成本人' }, { id: 2, name: '合成其他教師' }] } });
    if (path === '/prisons/options') return route.fulfill({ json: { data: [{ id: 8, name: '合成監所', address: '合成地址八號', active: true }] } });
    if (path === '/sessions' && request.method() === 'GET') {
      const from = url.searchParams.get('from') || '', to = url.searchParams.get('to') || '9999-12-31';
      const teacher = url.searchParams.get('teacher_id');
      return route.fulfill({ json: { data: courses.filter(item => item.service_date >= from && item.service_date <= to && (!teacher || item.assignments.some(a => a.teacher_id === Number(teacher)))) } });
    }
    const detail = path.match(/^\/sessions\/(\d+)$/);
    if (detail) {
      const item = courses.find(item => item.id === Number(detail[1]))!;
      if (request.method() === 'PUT') {
        const body = request.postDataJSON();
        writes.push({ path, body });
        expect(admin).toBe(true);
        Object.assign(item, body, { version: item.version + 1 });
      }
      return route.fulfill({ json: item });
    }
    const attendance = path.match(/^\/assignments\/(\d+)\/attendance$/);
    if (attendance) {
      const body = request.postData() || '';
      writes.push({ path, body });
      expect(body).toMatch(/name="mode"\r\n\r\nself/);
      const item = courses.find(item => item.assignments[0].id === Number(attendance[1]))!;
      item.assignments[0].attendance = { kind: item.service_date < '2026-10-02' ? 'late_check_in' : 'check_in', present: true, at: now, service_date: item.service_date };
      return route.fulfill({ json: item.assignments[0] });
    }
    const photo = path.match(/^\/files\/(\d+)\/download$/);
    if (photo) {
      const id = Number(photo[1]); files.push(id);
      const item = courses.find(item => item.assignments[0].attendance?.photo_id === id);
      const allowed = admin || (attendancePermission && item?.assignments[0].teacher_id === 1);
      return allowed ? route.fulfill({ contentType: 'image/png', body: png }) : route.fulfill({ status: 403, json: { message: '禁止存取合成照片' } });
    }
    expect(request.method(), `unexpected mutation ${path}`).toBe('GET');
    return route.fulfill({ json: { data: [] } });
  });
  return { writes, files };
}
async function dayList(page: Page, all = false) {
  await page.goto('/app/calendar');
  if (all) await page.getByTestId('calendar-teacher-filter').selectOption('all');
  await page.locator('[data-date="2026-10-02"] .calendar-day-tap').click();
  return page.getByTestId('calendar-day-sheet');
}
async function choose(page: Page, id: number) {
  await page.getByTestId('calendar-day-sheet').locator(`.day-session[data-session-id="${id}"]`).click();
  const detail = page.getByTestId('calendar-session-detail');
  await expect(detail).toBeVisible();
  await expect(detail).toHaveAttribute('role', 'dialog');
  return detail;
}
test.beforeEach(async ({ page }, testInfo) => {
  await page.clock.install({ time: new Date(now) });
  await page.clock.setFixedTime(new Date(now));
  if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 720 });
});

test('day list marks only own courses and teacher opens complete read-only detail', async ({ page }) => {
  const own = course(21), other = course(22, 2, true);
  const mock = await workspace(page, [own, other], false, true, true);
  const sheet = await dayList(page, true);
  await expect(sheet.locator('[data-session-id="21"]')).toContainText('我的課程');
  await expect(sheet.locator('[data-session-id="22"]').getByTestId('calendar-my-session')).toHaveCount(0);
  const detail = await choose(page, 21);
  for (const text of [own.title, own.class_name, own.prison, own.prison_address, own.location, '2026-10-02', '09:00', '10:00', '12', '合成本人']) await expect(detail).toContainText(text);
  await expect(detail).toContainText('未簽到');
  await expect(detail.locator('input,select,textarea')).toHaveCount(0);
  await expect(detail.getByRole('button', { name: '補登簽到', exact: true })).toHaveCount(0);
  await expect(detail.getByTestId('session-detail-edit')).toHaveCount(0);
  await expect(detail.getByTestId('self-check-in')).toBeVisible();
  expect(mock.writes).toHaveLength(0);
  await dayList(page, true);
  const foreign = await choose(page, 22);
  await expect(foreign.getByTestId('self-check-in')).toHaveCount(0);
  await expect(foreign.getByTestId('attendance-photo-thumbnail')).toHaveCount(0);
  expect(mock.files).not.toContain(922);
  const width = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.viewport + 2);
});

test('admin must enter edit mode in the same dialog to change complete session fields', async ({ page }) => {
  const own = course(23, 1, true), other = course(24, 2, true);
  const mock = await workspace(page, [own, other], true);
  await dayList(page, true);
  const detail = await choose(page, 24);
  await expect(detail.locator('input,select,textarea')).toHaveCount(0);
  await expect(detail).toContainText('2026-10-02 21:30:00');
  const thumbnail = detail.getByTestId('attendance-photo-thumbnail');
  await expect(thumbnail).toBeVisible();
  await expect(thumbnail).toHaveAttribute('src', '/api/v1/files/924/download');
  await expect.poll(() => thumbnail.evaluate(image => (image as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
  const size = await thumbnail.boundingBox();
  expect(size!.width).toBeLessThanOrEqual(220);
  expect(size!.height).toBeLessThanOrEqual(160);
  await detail.getByTestId('session-detail-edit').click();
  await expect(page.getByRole('dialog')).toHaveCount(1);
  for (const label of ['課程主題', '班級名稱', '監所／單位', '上課位置', '參與人數', '服務日期', '開始時間', '結束時間', '異動說明']) await expect(detail.getByLabel(label, { exact: true })).toBeVisible();
  await expect(detail.getByRole('button', { name: '補登簽到', exact: true })).toBeVisible();
  await detail.getByTestId('session-detail-back').click();
  await expect(detail.locator('input,select,textarea')).toHaveCount(0);
  expect(mock.writes).toHaveLength(0);
  await detail.getByTestId('session-detail-edit').click();
  await detail.getByLabel('上課位置', { exact: true }).fill('合成變更後教室');
  await detail.getByLabel('異動說明', { exact: true }).fill('合成管理編輯驗收');
  await detail.getByRole('button', { name: '儲存場次', exact: true }).click();
  await expect.poll(() => mock.writes.length).toBe(1);
  expect(mock.writes[0]).toMatchObject({ path: '/sessions/24', body: { location: '合成變更後教室', reason: '合成管理編輯驗收', version: 1 } });
});

test('own photo is a bounded thumbnail and absence of attendance permission never requests it', async ({ page }) => {
  const item = course(25, 1, true);
  const mock = await workspace(page, [item]);
  await dayList(page);
  const detail = await choose(page, 25);
  await expect(detail.getByTestId('attendance-photo-thumbnail')).toBeVisible();
  await expect.poll(() => mock.files).toContain(925);
  await expect(detail).toContainText('21:30:00');
  await expect(detail.getByTestId('self-check-in')).toHaveCount(0);
  await page.unroute('**/api/v1/**');
  const denied = await workspace(page, [item], false, false);
  await dayList(page);
  const hidden = await choose(page, 25);
  await expect(hidden.getByTestId('attendance-photo-thumbnail')).toHaveCount(0);
  expect(denied.files).toHaveLength(0);
});

test('agenda event first opens its day list and late self check-in remains available in read-only detail', async ({ page }) => {
  const today = course(26), past = course(27, 1, false, '2026-10-01');
  const mock = await workspace(page, [today, past]);
  await page.goto('/app/calendar');
  await page.getByRole('button', { name: '議程', exact: true }).click();
  await page.locator(`.calendar button[data-calendar-session-id="${past.id}"]`).click();
  await expect(page.getByTestId('calendar-day-sheet')).toBeVisible();
  await expect(page.getByTestId('calendar-session-detail')).toHaveCount(0);
  const detail = await choose(page, past.id);
  await expect(detail.getByTestId('session-detail-edit')).toHaveCount(0);
  await detail.getByTestId('self-late-check-in').click();
  await page.getByTestId('self-attendance-reason').fill('合成過日補簽原因');
  await page.getByTestId('self-attendance-submit').click();
  await expect.poll(() => mock.writes.length).toBe(1);
  expect(mock.writes[0].path).toBe('/assignments/270/attendance');
  expect(mock.writes[0].body).toContain('合成過日補簽原因');
});

test('admin filter defaults to own courses and switches to all or one synthetic teacher', async ({ page }) => {
  const mock = await workspace(page, [course(31), course(32, 2)], true);
  await page.goto('/app/calendar');
  await page.getByRole('button', { name: '議程', exact: true }).click();
  const filter = page.getByTestId('calendar-teacher-filter');
  await expect(filter).toHaveValue('mine');
  await expect(page.locator('.calendar button[data-calendar-session-id="31"]')).toBeVisible();
  await expect(page.locator('.calendar button[data-calendar-session-id="32"]')).toHaveCount(0);
  await filter.selectOption('all');
  await expect(page.locator('.calendar button[data-calendar-session-id="32"]')).toBeVisible();
  await page.locator('.calendar button[data-calendar-session-id="31"]').click();
  const sheet = page.getByTestId('calendar-day-sheet');
  await expect(sheet.locator('.day-session')).toHaveCount(2);
  await expect(sheet.locator('[data-session-id="31"]').getByTestId('calendar-my-session')).toBeVisible();
  await expect(sheet.locator('[data-session-id="32"]').getByTestId('calendar-my-session')).toHaveCount(0);
  await sheet.getByRole('button', { name: '關閉', exact: true }).click();
  await filter.selectOption('2');
  await expect(page.locator('.calendar button[data-calendar-session-id="31"]')).toHaveCount(0);
  await expect(page.locator('.calendar button[data-calendar-session-id="32"]')).toBeVisible();
  expect(mock.writes).toHaveLength(0);
});

test('ordinary teacher cannot select all or another teacher', async ({ page }) => {
  await workspace(page, [course(33), course(34, 2)]);
  await page.goto('/app/calendar');
  await page.getByRole('button', { name: '議程', exact: true }).click();
  await expect(page.getByTestId('calendar-teacher-filter').locator('option[value="all"],option[value="2"]')).toHaveCount(0);
  await expect(page.locator('.calendar button[data-calendar-session-id="34"]')).toHaveCount(0);
  await expect(page.locator('.calendar button[data-calendar-session-id="33"]')).toBeVisible();
});

test('closing the day list cancels a pending detail opening even when its response arrives later', async ({ page }) => {
  const item = course(35);
  const mock = await workspace(page, [item]);
  let release!: () => void, started!: () => void;
  const gate = new Promise<void>(resolve => { release = resolve; });
  const pending = new Promise<void>(resolve => { started = resolve; });
  await page.route('**/api/v1/sessions/35', async route => {
    started();
    await gate;
    await route.fulfill({ json: item });
  });
  const sheet = await dayList(page);
  await sheet.locator('[data-session-id="35"]').click();
  await pending;
  await expect(sheet).toBeVisible();
  await sheet.getByRole('button', { name: '關閉', exact: true }).click();
  await expect(sheet).toBeHidden();
  const response = page.waitForResponse(result => new URL(result.url()).pathname === '/api/v1/sessions/35');
  release();
  await (await response).finished();
  // Allow Vue's response handler and the following rendering frames to settle.
  await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
  await expect(page.getByTestId('calendar-session-detail')).toHaveCount(0);
  await expect(sheet).toBeHidden();
  expect(mock.writes).toHaveLength(0);
});
