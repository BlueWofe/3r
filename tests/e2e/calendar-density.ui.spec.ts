import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { apiContext, demoPassword, json, login, mutate, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

test('dense calendar days stay compact and expose every session in a keyboard-accessible day sheet', async ({ page, isMobile }, testInfo) => {
  const admin = await apiContext();
  const titles: string[] = [];
  try {
    await login(admin, '0900000001');
    const { data: users } = await json<{ data: { id: number; phone: string }[] }>(
      await admin.get('/api/v1/users'),
    );
    const teacher = users.find(user => user.phone === '0900000002');
    expect(teacher, 'synthetic teacher account exists').toBeTruthy();

    const serviceDate = await findEmptyDay(admin, isMobile ? 17 : 2);
    for (let index = 0; index < 8; index++) {
      const title = unique(`密集行事曆${index + 1}`);
      titles.push(title);
      const startMinute = 8 * 60 + index * 12;
      const start = formatTime(startMinute);
      const end = formatTime(startMinute + 10);
      await json(await mutate(admin, 'post', '/api/v1/sessions', {
        title,
        prison: '示範場域',
        location: '行事曆密集場次驗收',
        participant_count: 0,
        service_date: serviceDate,
        start_time: start,
        end_time: end,
        teacher_ids: [teacher!.id],
        override_conflict: true,
        reason: '隔離的行事曆密集顯示驗收資料',
      }));
    }

    await loginTeacher(page, teacher!.phone);
    for (const width of [320, 390]) {
      await page.setViewportSize({ width, height: 844 });
      await page.goto('/app/calendar');
      await page.getByRole('button', { name: '月', exact: true }).click();
      await navigateToMonth(page, serviceDate);

      const toolbar = page.locator('.workhead .toolbar');
      const toolbarButtons = toolbar.getByRole('button');
      await expect(toolbarButtons).toHaveCount(6);
      const toolbarRows = await toolbarButtons.evaluateAll(buttons =>
        buttons.map(button => button.getBoundingClientRect().top),
      );
      expect(Math.max(...toolbarRows) - Math.min(...toolbarRows), `toolbar stays on one row at ${width}px`).toBeLessThanOrEqual(2);
      await expectNoHorizontalOverflow(page, `calendar at ${width}px`);

      const cells = page.locator('.calendar .day');
      const heights = await cells.evaluateAll(elements =>
        elements.map(element => element.getBoundingClientRect().height),
      );
      expect(heights.length).toBe(42);
      expect(Math.max(...heights) - Math.min(...heights), `month cells stay equally tall at ${width}px`).toBeLessThanOrEqual(1);

      const dayButton = page.getByRole('button', { name: `${serviceDate}，8 場服務`, exact: true });
      await expect(dayButton).toBeVisible();
      await expect(page.locator(`.calendar .day[data-date="${serviceDate}"]`)).toContainText('+5');
      await dayButton.click();

      const sheet = page.locator('dialog.day-sheet');
      await expect(sheet).toBeVisible();
      await expect(sheet.locator('.day-session')).toHaveCount(8);
      for (const [index, title] of titles.entries()) await expect(sheet.locator('.day-session').nth(index)).toContainText(title);
      await page.screenshot({ path: testInfo.outputPath(`calendar-day-sheet-${width}.png`) });

      await page.keyboard.press('Escape');
      await expect(sheet).toBeHidden();
      await expect(dayButton).toBeFocused();

      await dayButton.click();
      await sheet.locator('.day-session').filter({ hasText: titles[0] }).click();
      await expect(sheet).toBeHidden();
      await expect(page.getByRole('heading', { name: titles[0], exact: true })).toBeVisible();
      await page.getByRole('button', { name: '關閉', exact: true }).click();
      await expectNoHorizontalOverflow(page, `calendar after opening service at ${width}px`);
    }
  } finally {
    await admin.dispose();
  }
});

async function findEmptyDay(api: Awaited<ReturnType<typeof apiContext>>, offset: number) {
  const now = new Date();
  const candidates: string[] = [];
  for (let day = offset; day < offset + 8; day++) {
    const date = new Date(now.getFullYear(), now.getMonth() + 1, day);
    candidates.push(formatTaipeiDate(date));
  }
  for (const date of candidates) {
    const { data } = await json<{ data: unknown[] }>(
      await api.get(`/api/v1/sessions?from=${date}&to=${date}`),
    );
    if (data.length === 0) return date;
  }
  throw new Error('Could not find an empty future calendar date for the synthetic fixture.');
}

function formatTime(totalMinutes: number) {
  return `${String(Math.floor(totalMinutes / 60)).padStart(2, '0')}:${String(totalMinutes % 60).padStart(2, '0')}`;
}

function formatTaipeiDate(date: Date) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  return `${parts.find(part => part.type === 'year')!.value}-${parts.find(part => part.type === 'month')!.value}-${parts.find(part => part.type === 'day')!.value}`;
}

async function navigateToMonth(page: Page, date: string) {
  const [year, month] = date.split('-').map(Number);
  const expected = `${year} 年 ${month} 月`;
  const monthLabel = page.locator('.workhead .muted').first();
  for (let attempt = 0; attempt < 2; attempt++) {
    const label = (await monthLabel.textContent())?.trim();
    if (label === expected) return;
    await page.getByRole('button', { name: '→', exact: true }).click();
  }
  await expect(monthLabel).toHaveText(expected);
}

async function loginTeacher(page: Page, phone: string) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill(phone);
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}

async function expectNoHorizontalOverflow(page: Page, description: string) {
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(dimensions.document, dimensions.body), description).toBeLessThanOrEqual(dimensions.viewport + 2);
}
