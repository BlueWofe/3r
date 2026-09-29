import { expect, test } from '@playwright/test';
import { demoPassword } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

test('month and week calendars begin Sunday and month navigation crosses February into March', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000002');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });

  await page.clock.setFixedTime(new Date('2026-01-31T12:00:00+08:00'));
  await page.goto('/app/calendar');
  await expect(page.getByRole('heading', { name: '行事曆', exact: true })).toBeVisible();

  await page.getByRole('button', { name: '月', exact: true }).click();
  const monthLabel = page.locator('.workhead .muted').first();
  const nextMonth = page.getByRole('button', { name: '→', exact: true });
  await expect(monthLabel).toHaveText('2026 年 1 月');
  await expect(page.locator('.calendar .day')).toHaveCount(42);
  await expectFirstCalendarDateIsSunday(page);

  await nextMonth.click();
  await expect(monthLabel).toHaveText('2026 年 2 月');
  await expect(page.locator('.calendar .day')).toHaveCount(42);
  await expect(page.locator('.calendar .day').first()).toHaveAttribute('data-date', '2026-02-01');
  await expectFirstCalendarDateIsSunday(page);

  await nextMonth.click();
  await expect(monthLabel).toHaveText('2026 年 3 月');
  await expect(page.locator('.calendar .day')).toHaveCount(42);
  await expect(page.locator('.calendar .day').first()).toHaveAttribute('data-date', '2026-03-01');
  await expectFirstCalendarDateIsSunday(page);

  await page.getByRole('button', { name: '週', exact: true }).click();
  await expect(page.locator('.calendar .day')).toHaveCount(7);
  await expect(page.locator('.calendar .day').first()).toHaveAttribute('data-date', '2026-03-01');
  await expectFirstCalendarDateIsSunday(page);
});

async function expectFirstCalendarDateIsSunday(page: import('@playwright/test').Page) {
  const date = await page.locator('.calendar .day').first().getAttribute('data-date');
  expect(date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(new Date(`${date}T00:00:00Z`).getUTCDay()).toBe(0);
}
