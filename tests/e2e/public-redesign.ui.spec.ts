import { expect, test } from '@playwright/test';
import { demoPassword, unique } from './helpers';

test.use({ timezoneId: 'Asia/Taipei' });

test('public redesign pages stay readable at four widths and show published organization structure', async ({ page }, testInfo) => {
  const department = unique('合成部門');
  const staff = unique('合成同工');
  await page.route('**/api/v1/public/pages/organization', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data: {
        id: 987654321,
        kind: 'page',
        slug: 'organization',
        title: '組織與同工',
        status: 'published',
        body: '組織介紹測試本文。',
        body_html: '<p>組織介紹測試本文。</p>',
        metadata: {
          organization_levels: ['理事會', '執行團隊'],
          departments: [{ name: department, description: '公開的合成部門說明' }],
          team: [{ name: staff, role: '合成職務', bio: '公開的合成同工介紹' }],
        },
      },
    }),
  }));

  const publicPages = [
    { path: '/', file: 'home', heading: /讓每一段生命/ },
    { path: '/about', file: 'about', heading: '在恩典裡，重建盼望' },
    { path: '/donate', file: 'donate', heading: '把支持，化成陪伴' },
  ] as const;

  for (const width of [320, 390, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const item of publicPages) {
      if (item.path === '/about') {
        // Client navigation lets the deterministic public-API fixture exercise the rendered structure.
        await page.goto('/');
        await page.getByRole('link', { name: '認識我們', exact: true }).click();
      } else {
        await page.goto(item.path);
      }
      await expect(page.getByRole('heading', { name: item.heading })).toBeVisible();
      await expect(page.locator('a[href^="/app/admin/"]')).toHaveCount(0);
      await expectNoOverflow(page, `${item.path} at ${width}px`);

      if (item.path === '/about') {
        const anchors = page.locator('nav[aria-label="協會介紹章節"]');
        await expect(anchors.getByRole('link', { name: '宗旨', exact: true })).toHaveAttribute('href', '#mission');
        await expect(anchors.getByRole('link', { name: '沿革', exact: true })).toHaveAttribute('href', '#history');
        await expect(anchors.getByRole('link', { name: '組織與同工', exact: true })).toHaveAttribute('href', '#organization');
        await expect(page.getByRole('heading', { name: department, exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: staff, exact: true })).toBeVisible();
      }
      if (item.path === '/donate') {
        await expect(page.locator('.giving-mode')).toContainText(/模擬奉獻|不會扣款/);
        await expect(page.locator('body')).not.toContainText(/(?:銀行|郵局)?帳號\s*[:：]?\s*\d{8,}/);
        await expect(page.locator('body')).not.toContainText(/可開立收據|收據申請|捐款收據|扣稅資格/);
      }

      await page.screenshot({
        path: testInfo.outputPath(`${item.file}-${width}px.png`),
        fullPage: true,
      });
    }
  }
});

test('donors can return from login, choose a custom amount, and receive guarded Chinese mock results', async ({ page }) => {
  await page.goto('/donate');
  const loginPrompt = page.locator('a[href*="returnTo"]');
  await expect(loginPrompt).toBeVisible();
  await loginPrompt.click();
  await expect(page).toHaveURL(/\/login\?returnTo=(?:%2F|\/)donate$/);
  await page.getByLabel('手機號碼').fill('0900000003');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).toHaveURL(/\/donate$/);

  const amount = page.getByLabel('支持金額（元）');
  await expect(amount).toHaveValue('500');
  for (const [label, value] of [['500 元', '500'], ['1,000 元', '1000'], ['2,000 元', '2000'], ['5,000 元', '5000']]) {
    await page.getByRole('button', { name: label, exact: true }).click();
    await expect(amount).toHaveValue(value);
  }

  const resultExpectations = [
    { button: '模擬成功', api: 'success', copy: /成功/ },
    { button: '模擬失敗', api: 'failed', copy: /失敗|未成功/ },
    { button: '取消', api: 'cancelled', copy: /取消|已取消/ },
  ] as const;
  let resultIndex = 0;

  for (const expected of resultExpectations) {
    if (resultIndex > 0) await page.goto('/donate');
    const donationAmount = page.getByLabel('支持金額（元）');
    if (resultIndex === 0) {
      await donationAmount.fill('1357');
      await page.getByLabel('支持用途').selectOption('監所關懷');
    } else {
      await donationAmount.fill(resultIndex === 1 ? '725' : '825');
      await page.getByLabel('支持用途').selectOption('家庭支持');
    }
    await page.getByRole('button', { name: '確認奉獻內容', exact: true }).click();
    await expect(page.getByRole('heading', { name: '確認奉獻內容', exact: true })).toBeVisible();
    await expect(page.locator('.giving-summary')).toContainText(`NT$ ${Number(resultIndex === 0 ? 1357 : resultIndex === 1 ? 725 : 825).toLocaleString('zh-TW')}`);
    await expect(page.locator('.giving-summary')).toContainText(resultIndex === 0 ? '監所關懷' : '家庭支持');

    let createRequests = 0;
    let donationResponse: any;
    await page.route('**/api/v1/donations', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      createRequests++;
      const response = await route.fetch();
      donationResponse = await response.json();
      await new Promise(resolve => setTimeout(resolve, 700));
      await route.fulfill({ response });
    });
    const createButton = page.getByRole('button', { name: '確認建立模擬捐款', exact: true });
    await createButton.click();
    await expect(page.getByRole('button', { name: '處理中…', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: '處理中…', exact: true }).evaluate(button =>
      button.dispatchEvent(new Event('click', { bubbles: true })),
    );
    await expect.poll(() => createRequests).toBe(1);
    await expect(page.getByRole('heading', { name: /確認模擬結果|奉獻模擬結果/ })).toBeVisible();
    const expectedAmount = resultIndex === 0 ? '1357' : resultIndex === 1 ? '725' : '825';
    expect(donationResponse).toMatchObject({ amount: Number(expectedAmount), purpose: resultIndex === 0 ? '監所關懷' : '家庭支持', status: 'pending' });
    await page.unroute('**/api/v1/donations');

    const responsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && /\/api\/v1\/donations\/\d+\/simulate$/.test(response.url()),
    );
    await page.getByRole('button', { name: expected.button, exact: true }).click();
    const response = await responsePromise;
    expect(response.ok(), `mock result ${expected.api} is accepted`).toBeTruthy();
    expect((await response.json()).status).toBe(expected.api);
    const result = page.locator('.giving-result.notice');
    await expect(result).toContainText(expected.copy);
    await expect(result).toContainText(/沒有產生真實付款|尚未產生真實付款/);
    await expect(page.getByRole('button', { name: expected.button, exact: true })).toHaveCount(0);
    resultIndex++;
  }
});

test('organization editor updates structured fields without dropping unrelated metadata', async ({ page }) => {
  const existing = {
    id: 987654322,
    version: 4,
    kind: 'page',
    title: '組織與同工合成編輯資料',
    slug: 'organization',
    body: '<p>組織編輯測試本文。</p>',
    body_format: 'html',
    status: 'published',
    category: '',
    summary: '',
    sort_order: 0,
    author_name: '合成編輯',
    published_at: '2026-09-28T10:00:00+08:00',
    metadata: {
      organization_levels: ['原有層級'],
      departments: [{ name: '原有部門', description: '既有說明' }],
      team: [{ name: '原有同工', role: '既有職務', bio: '既有介紹' }],
      retained_integration_key: { enabled: true },
    },
  };
  let writePayload: Record<string, any> | undefined;

  await loginAsAdmin(page);
  await page.route('**/api/v1/contents*', async route => {
    const request = route.request();
    if (request.method() === 'GET' && new URL(request.url()).pathname.endsWith('/contents')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [existing] }) });
    }
    if (request.method() === 'PUT' && new URL(request.url()).pathname.endsWith(`/contents/${existing.id}`)) {
      writePayload = request.postDataJSON();
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...existing, ...writePayload }) });
    }
    return route.continue();
  });
  await page.goto('/app/admin/content?section=pages');
  await expect(page.getByRole('heading', { name: '內容管理', exact: true })).toBeVisible();
  await page.getByRole('button', { name: '編輯', exact: true }).click();
  await page.getByLabel('層級 1 名稱').fill('理事會');
  await page.getByRole('button', { name: '新增層級', exact: true }).click();
  await page.getByLabel('層級 2 名稱').fill('執行團隊');
  await page.getByLabel('部門 1 名稱').fill('關懷服務');
  await page.getByLabel('部門 1 說明').fill('陪伴與支持');
  await page.getByRole('button', { name: '新增部門', exact: true }).click();
  await page.getByLabel('部門 2 名稱').fill('家庭支持');
  await page.getByLabel('部門 2 說明').fill('同行服務');
  await page.getByLabel('同工 1 姓名').fill('同工甲');
  await page.getByLabel('同工 1 職務').fill('督導');
  await page.getByLabel('同工 1 簡介').fill('合成介紹甲');
  await page.getByRole('button', { name: '新增同工', exact: true }).click();
  await page.getByLabel('同工 2 姓名').fill('同工乙');
  await page.getByLabel('同工 2 職務').fill('專員');
  await page.getByLabel('同工 2 簡介').fill('合成介紹乙');
  await page.getByRole('button', { name: '儲存', exact: true }).click();

  await expect.poll(() => writePayload).toBeTruthy();
  expect(writePayload?.metadata).toMatchObject({
    organization_levels: ['理事會', '執行團隊'],
    departments: [
      { name: '關懷服務', description: '陪伴與支持' },
      { name: '家庭支持', description: '同行服務' },
    ],
    team: [
      { name: '同工甲', role: '督導', bio: '合成介紹甲' },
      { name: '同工乙', role: '專員', bio: '合成介紹乙' },
    ],
    retained_integration_key: { enabled: true },
  });
  await expect(page.locator('.modal')).toBeHidden();
});

async function loginAsAdmin(page: import('@playwright/test').Page) {
  await page.goto('/login');
  await page.getByLabel('手機號碼').fill('0900000001');
  await page.getByLabel('密碼', { exact: true }).fill(demoPassword!);
  await page.locator('form').getByRole('button', { name: '登入', exact: true }).click();
  await expect(page).not.toHaveURL(/\/login(?:\?|$)/, { timeout: 15_000 });
}

async function expectNoOverflow(page: import('@playwright/test').Page, context: string) {
  const { client, document, body } = await page.evaluate(() => ({
    client: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(document, body), context).toBeLessThanOrEqual(client + 2);
}
