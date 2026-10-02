import { test, expect, type Page } from '@playwright/test';

test.use({ timezoneId: 'Asia/Taipei' });
const actualAt = '2026-10-02T21:30:00+08:00';
type Attendance = null | { kind: string; at: string; service_date: string; present: boolean; reason?: string; photo_id?: number };
function session(id: number, date = '2026-10-02', status = 'scheduled', assignmentStatus = 'assigned') {
  return {
    id, title: `合成簽到行程 ${id}`, class_name: '合成班', prison: '合成監所', location: '合成教室',
    service_date: date, start_time: '09:00', end_time: '10:00', status, version: 1, participant_count: 0,
    assignments: [{ id: id * 10, teacher_id: 1, teacher: { id: 1, name: '合成同工' }, status: assignmentStatus, attendance: null as Attendance }],
    invitations: [], events: [],
  };
}
async function mockWorkspace(page: Page, sessions: ReturnType<typeof session>[], admin = false) {
  await page.clock.install({ time: new Date(actualAt) });
  await page.clock.pauseAt(new Date(actualAt));
  const posts: { path: string; body: string }[] = [];
  let lists = 0;
  // Intercept every API request; no live attendance is created or changed.
  await page.route('**/api/v1/**', async route => {
    const request = route.request(), url = new URL(request.url());
    const path = url.pathname.replace('/api/v1', '');
    if (path === '/auth/me') return route.fulfill({ json: { user: {
      id: 1, name: '合成同工', phone: '0900000001', must_change_password: false,
      roles: [{ id: 1, name: '合成教師', slug: 'teacher' }, ...(admin ? [{ id: 2, name: '合成管理員', slug: 'system-admin' }] : [])],
      permissions: ['schedule.read.own', 'schedule.update.own', 'attendance.create.own', ...(admin ? ['schedule.read.all', 'schedule.update.all', 'attendance.update.all'] : [])],
    } } });
    if (path === '/auth/csrf') return route.fulfill({ json: { csrf_token: 'mock-csrf' } });
    if (path === '/notifications') return route.fulfill({ json: { data: [], unread_count: 0 } });
    if (path === '/teachers') return route.fulfill({ json: { data: [{ id: 1, name: '合成同工' }] } });
    if (path === '/sessions') {
      lists++;
      const from = url.searchParams.get('from') || '', to = url.searchParams.get('to') || '9999-12-31';
      return route.fulfill({ json: { data: sessions.filter(item => item.service_date >= from && item.service_date <= to) } });
    }
    if (/^\/sessions\/\d+$/.test(path)) return route.fulfill({ json: sessions.find(item => item.id === Number(path.split('/').pop())) });
    if (/^\/assignments\/\d+\/attendance$/.test(path) && request.method() === 'POST') {
      expect(request.headers()['content-type']).toMatch(/^multipart\/form-data; boundary=/);
      const body = request.postData() || '';
      posts.push({ path, body });
      const selected = sessions.find(item => item.assignments[0].id === Number(path.split('/')[2]));
      expect(selected).toBeTruthy();
      const reason = body.match(/name="reason"\r\n\r\n([^]*?)\r\n--/)?.[1];
      selected!.assignments[0].attendance = {
        kind: selected!.service_date === '2026-10-02' ? 'check_in' : 'late_check_in',
        at: actualAt, service_date: selected!.service_date, present: true,
        ...(reason ? { reason } : {}), ...(body.includes('name="photo"') ? { photo_id: 99 } : {}),
      };
      selected!.version++;
      return route.fulfill({ json: selected!.assignments[0] });
    }
    return route.fulfill({ json: { data: [] } });
  });
  return { posts, listRequests: () => lists };
}
async function openCalendarSession(page: Page, item: ReturnType<typeof session>, view = '月') {
  await page.goto('/app/calendar');
  await page.getByRole('button', { name: view, exact: true }).click();
  await page.locator(`[data-date="${item.service_date}"] .calendar-day-tap`).click();
  await expect(page.getByRole('dialog')).toContainText(`${item.service_date} 的行程`);
  await page.getByRole('button', { name: new RegExp(item.title) }).click();
  await expect(page.getByRole('heading', { name: item.title, exact: true })).toBeVisible();
}
function expectSelfPost(post: { path: string; body: string }, assignmentId: number, photo = false) {
  expect(post.path).toBe(`/assignments/${assignmentId}/attendance`);
  expect(post.body).toMatch(/name="mode"\r\n\r\nself\r\n/);
  expect(post.body).not.toContain('name="present"');
  expect(post.body.includes('name="photo"')).toBe(photo);
}
for (const admin of [false, true]) {
  test(`${admin ? 'admin + teacher' : 'teacher'} checks in after today's end time without reason or photo`, async ({ page }, testInfo) => {
    if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 720 });
    const item = session(11);
    const mock = await mockWorkspace(page, [item], admin);
    await page.goto('/app');
    await expect(page.getByText(item.title, { exact: true })).toBeVisible();
    await page.getByTestId('self-check-in').click();
    const dialog = page.getByTestId('self-attendance-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('select')).toHaveCount(0);
    await expect(page.getByTestId('self-attendance-reason')).not.toHaveAttribute('required');
    await expect(page.getByTestId('self-attendance-photo')).not.toHaveAttribute('required');
    const before = mock.listRequests();
    await page.getByTestId('self-attendance-submit').click();
    await expect(dialog).toBeHidden();
    expect(mock.posts).toHaveLength(1);
    expectSelfPost(mock.posts[0], 110);
    await expect.poll(mock.listRequests).toBeGreaterThan(before);
    await expect(page.getByTestId('attendance-summary')).toContainText('21:30');
    await expect(page.getByTestId('self-check-in')).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('attendance-summary')).toContainText('21:30');
    await expect(page.getByTestId('self-check-in')).toHaveCount(0);
  });
}
test('past calendar appointment requires a reason, accepts no photo, and displays actual check-in time', async ({ page }, testInfo) => {
  if (testInfo.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 720 });
  const item = session(12, '2026-10-01');
  const mock = await mockWorkspace(page, [item], true);
  await openCalendarSession(page, item);
  await page.getByTestId('self-late-check-in').click();
  await expect(page.getByTestId('self-attendance-reason')).toHaveAttribute('required');
  await page.getByTestId('self-attendance-submit').click();
  expect(mock.posts).toHaveLength(0);
  await expect(page.getByTestId('self-attendance-dialog')).toBeVisible();
  expect(await page.getByTestId('self-attendance-reason').evaluate(element => (element as HTMLTextAreaElement).validity.valueMissing)).toBe(true);
  // Whitespace passes native required validation; the application must reject it too.
  await page.getByTestId('self-attendance-reason').fill('   ');
  await page.getByTestId('self-attendance-submit').click();
  expect(mock.posts).toHaveLength(0);
  await expect(page.getByTestId('self-attendance-error')).toContainText(/原因/);
  await page.getByTestId('self-attendance-reason').fill('合成驗收：忘記當日操作');
  await page.getByTestId('self-attendance-submit').click();
  await expect(page.getByTestId('self-attendance-dialog')).toBeHidden();
  expect(mock.posts).toHaveLength(1);
  expectSelfPost(mock.posts[0], 120);
  expect(mock.posts[0].body).toContain('合成驗收：忘記當日操作');
  await openCalendarSession(page, item, '週');
  await expect(page.getByTestId('attendance-summary')).toContainText('21:30');
  await expect(page.getByTestId('attendance-summary')).toContainText(/2026[\/-]10[\/-]02/);
  await expect(page.getByTestId('self-late-check-in')).toHaveCount(0);
});
test('photo remains optional but can accompany self check-in from calendar detail', async ({ page }) => {
  const item = session(13);
  const mock = await mockWorkspace(page, [item], true);
  await openCalendarSession(page, item);
  await page.getByTestId('self-check-in').click();
  await page.getByTestId('self-attendance-photo').setInputFiles({ name: 'synthetic.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64') });
  await page.getByTestId('self-attendance-submit').click();
  await expect(page.getByTestId('self-attendance-dialog')).toBeHidden();
  expect(mock.posts).toHaveLength(1);
  expectSelfPost(mock.posts[0], 130, true);
});
test('future, leave and cancelled appointments offer no self attendance action', async ({ page }) => {
  const items = [session(14, '2026-10-03'), session(15, '2026-10-02', 'scheduled', 'leave'), session(16, '2026-10-02', 'cancelled')];
  const mock = await mockWorkspace(page, items);
  for (const item of items) {
    await openCalendarSession(page, item);
    await expect(page.getByTestId('self-check-in')).toHaveCount(0);
    await expect(page.getByTestId('self-late-check-in')).toHaveCount(0);
  }
  expect(mock.posts).toHaveLength(0);
});
