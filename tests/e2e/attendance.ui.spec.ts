import { test, expect } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

function taipeiToday() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
}

test('teacher can check in today without a photo and cannot check in twice', async ({ page }) => {
  const admin = await apiContext();
  const title = unique('E2E 無照片簽到');
  let sessionId: number | undefined;
  try {
    await login(admin, '0900000001');
    const users = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const mobileTeacher = users.data.find(user => user.phone === '0900000004');
    expect(mobileTeacher, 'seeded teacher 4 should exist').toBeTruthy();

    // A same-day late-night appointment keeps the teacher check-in window open during desktop/mobile UI runs.
    const created = await json<Array<{ id: number; assignments: { id: number; teacher_id: number }[] }> | { id: number; assignments: { id: number; teacher_id: number }[] }>(
      await mutate(admin, 'post', '/api/v1/sessions', {
        title,
        prison: '示範場域',
        location: 'E2E 驗收用',
        participant_count: 0,
        service_date: taipeiToday(),
        start_time: '23:50',
        end_time: '23:59',
        teacher_ids: [mobileTeacher!.id],
        repeat_weeks: 1,
        override_conflict: true,
        reason: 'E2E 當日簽到 UI 建立測試場次',
      }),
    );
    const session = Array.isArray(created) ? created[0] : created;
    expect(session, 'API should create one test session').toBeTruthy();
    sessionId = session.id;
    expect(session.assignments.some(item => item.teacher_id === mobileTeacher!.id)).toBeTruthy();

    await page.goto('/login');
    await page.getByLabel('手機號碼').fill('0900000004');
    await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
    await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });

    await page.goto('/app/calendar');
    await page.getByRole('button', { name: '議程', exact: true }).click();
    const event = page.getByRole('button', { name: new RegExp(title) });
    await expect(event).toBeVisible({ timeout: 15_000 });
    await event.click();
    await expect(page.getByTestId('calendar-day-sheet')).toBeVisible();
    await page.getByTestId('calendar-day-sheet').getByRole('button', { name: new RegExp(title) }).click();

    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await page.getByTestId('self-check-in').click();
    await expect(page.getByTestId('self-attendance-dialog')).toBeVisible();
    await expect(page.getByTestId('self-attendance-photo')).toBeVisible();
    // Deliberately leave the optional photo input empty.
    await page.getByTestId('self-attendance-submit').click();
    await expect(page.getByRole('heading', { name: title })).toBeHidden({ timeout: 15_000 });

    const saved = await json<{ assignments: { teacher_id: number; attendance: unknown }[] }>(
      await admin.get(`/api/v1/sessions/${sessionId}`),
    );
    const attendance = saved.assignments.find(item => item.teacher_id === mobileTeacher!.id)?.attendance;
    expect(attendance, 'check-in should persist without a photo').toBeTruthy();

    await page.getByRole('button', { name: new RegExp(title) }).click();
    await page.getByTestId('calendar-day-sheet').getByRole('button', { name: new RegExp(title) }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.getByTestId('self-check-in')).toHaveCount(0);
  } finally {
    await admin.dispose();
  }
});
