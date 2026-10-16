import { chromium, expect, test, type CDPSession, type Page } from '@playwright/test';
import { openSyntheticProduct } from './fixtures/product-gallery';

// All application API calls use the synthetic fixture, including image downloads.
// No account, order, real upload, or local/GCP database is mutated.
const image = (page: Page) => page.getByTestId('product-lightbox-image');
const viewport = (page: Page) => page.getByTestId('product-lightbox-stage');
const zoom = (page: Page) => page.getByTestId('product-lightbox-zoom-level');

async function geometry(page: Page) {
  return image(page).evaluate(el => {
    const rect = el.getBoundingClientRect();
    const matrix = new DOMMatrixReadOnly(getComputedStyle(el).transform);
    return { scale: matrix.a, x: matrix.e, y: matrix.f, left: rect.left, right: rect.right,
      top: rect.top, bottom: rect.bottom, width: rect.width, height: rect.height,
      baseWidth: (el as HTMLElement).offsetWidth, baseHeight: (el as HTMLElement).offsetHeight };
  });
}
async function expectReset(page: Page) {
  await expect(zoom(page)).toHaveText('100%');
  await expect.poll(async () => {
    const g = await geometry(page);
    return [g.scale, g.x, g.y];
  }).toEqual([1, 0, 0]);
}
async function points(page: Page) {
  const box = await viewport(page).boundingBox();
  expect(box).not.toBeNull();
  return { x: box!.x + box!.width / 2, y: box!.y + box!.height / 2, box: box! };
}
async function pinch(page: Page, client: CDPSession, from: number, to: number, end = true) {
  const c = await points(page);
  const touches = (distance: number) => [
    { id: 1, x: c.x - distance / 2, y: c.y },
    { id: 2, x: c.x + distance / 2, y: c.y },
  ];
  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: touches(from) });
  for (let step = 1; step <= 8; step++) {
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: touches(from + (to - from) * step / 8) });
  }
  if (end) await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}
async function drag(page: Page, client: CDPSession, dx: number, dy: number) {
  const c = await points(page);
  // Stay in the viewport so this also exercises touch/pointer capture near edges.
  const startX = c.x - dx / 2, startY = c.y - dy / 2;
  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ id: 1, x: startX, y: startY }] });
  for (let step = 1; step <= 8; step++) {
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ id: 1, x: startX + dx * step / 8, y: startY + dy * step / 8 }] });
  }
  await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

test('desktop zoom controls clamp scale and reset image and focus across slides and reopen', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openSyntheticProduct(page, 3);
  const opener = page.getByTestId('product-gallery-open');
  const before = await page.evaluate(() => ({ overflow: document.body.style.overflow, viewport: document.querySelector('meta[name=viewport]')?.getAttribute('content') }));
  await opener.click();
  await expectReset(page);
  for (let n = 0; n < 6; n++) await page.getByRole('button', { name: '放大圖片', exact: true }).click();
  await expect(zoom(page)).toHaveText('400%');
  await expect(page.getByRole('button', { name: '放大圖片', exact: true })).toBeDisabled();
  expect((await geometry(page)).scale).toBe(4);
  await page.getByRole('button', { name: '原始大小', exact: true }).click();
  await expectReset(page);
  await page.getByRole('button', { name: '放大圖片', exact: true }).click();
  await page.keyboard.press('ArrowRight');
  await expect(image(page)).toHaveAttribute('src', /\/files\/9910\/download$/);
  await expectReset(page);
  await page.getByRole('button', { name: '放大圖片', exact: true }).click();
  for (let n = 0; n < 12; n++) {
    await page.keyboard.press('Tab');
    expect(await page.getByTestId('product-lightbox').evaluate(el => el.contains(document.activeElement))).toBeTruthy();
  }
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('product-lightbox')).not.toBeVisible();
  await expect(opener).toBeFocused();
  await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe(before.overflow);
  await opener.click();
  await expectReset(page);
  await expect(page.getByRole('button', { name: '縮小圖片', exact: true })).toBeDisabled();
  await expectReset(page);
  expect(await page.evaluate(() => document.querySelector('meta[name=viewport]')?.getAttribute('content'))).toBe(before.viewport);
  expect(before.viewport).not.toMatch(/user-scalable\s*=\s*(?:no|0)|maximum-scale\s*=\s*1(?:[,;\s]|$)/i);
  expect(errors).toEqual([]);
});

test('trusted mobile pinch zooms product only, bounded pan preserves slide, shrinking restores swipe', async ({ isMobile, baseURL }, testInfo) => {
  test.skip(!isMobile, 'Trusted CDP touch is checked once in a dedicated Chromium phone context.');
  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  try {
    await openSyntheticProduct(page, 3);
    const before = await page.evaluate(() => ({ overflow: document.body.style.overflow, viewport: document.querySelector('meta[name=viewport]')?.getAttribute('content') }));
    await page.getByTestId('product-gallery-open').tap();
    const client = await context.newCDPSession(page);
    await expectReset(page);
    const base = await geometry(page);
    await pinch(page, client, 50, 140);
    await expect.poll(async () => (await geometry(page)).scale).toBeGreaterThan(2);
    const enlarged = await geometry(page);
    expect(enlarged.scale).toBeLessThanOrEqual(4);
    expect(enlarged.width).toBeGreaterThan(base.width * 2);
    const source = await image(page).getAttribute('src');
    await drag(page, client, 160, 110);
    const panned = await geometry(page);
    expect(Math.abs(panned.x - enlarged.x) + Math.abs(panned.y - enlarged.y)).toBeGreaterThan(20);
    await expect(image(page)).toHaveAttribute('src', source!);
    // Repeated gestures reach the edge; the scaled photo must cover the viewport
    // or remain centered where the scaled image is smaller than the viewport.
    for (let n = 0; n < 6; n++) await drag(page, client, 160, 100);
    const edge = await geometry(page);
    const b = (await points(page)).box;
    expect(Math.abs(edge.x)).toBeLessThanOrEqual(Math.max(0, (edge.baseWidth * edge.scale - b.width) / 2) + 2);
    expect(Math.abs(edge.y)).toBeLessThanOrEqual(Math.max(0, (edge.baseHeight * edge.scale - b.height) / 2) + 2);
    await expect(image(page)).toHaveAttribute('src', source!);
    // More outward pinching clamps at 4x; it must not browser-zoom the document.
    await pinch(page, client, 35, 200);
    await expect(zoom(page)).toHaveText('400%');
    expect((await geometry(page)).scale).toBe(4);
    expect(await page.evaluate(() => visualViewport?.scale)).toBe(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
    expect(await page.evaluate(() => document.querySelector('meta[name=viewport]')?.getAttribute('content'))).toBe(before.viewport);
    // A finger lifting during pinch must not turn its remaining movement into
    // a carousel swipe, even when touch input is subsequently cancelled.
    await pinch(page, client, 60, 120, false);
    const c = await points(page);
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [{ id: 1, x: c.x - 60, y: c.y }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ id: 1, x: c.x + 60, y: c.y }] });
    await client.send('Input.dispatchTouchEvent', { type: 'touchCancel', touchPoints: [] });
    await expect(image(page)).toHaveAttribute('src', source!);
    await pinch(page, client, 220, 25);
    await expectReset(page);
    await drag(page, client, -170, 0);
    await expect(image(page)).toHaveAttribute('src', /\/files\/9910\/download$/);
    await expectReset(page);
    await testInfo.attach('phone-pinch-pan-geometry', { body: JSON.stringify({ base, enlarged, panned, edge, viewport: b }), contentType: 'application/json' });
    await page.keyboard.press('Escape');
    await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe(before.overflow);
    expect(errors).toEqual([]);
    await client.detach();
  } finally { await browser.close(); }
});

test('portrait photos clamp against actual contained content and remain usable at 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 700 });
  await openSyntheticProduct(page, 3, true, undefined, { width: 600, height: 1200 });
  await page.getByTestId('product-gallery-open').click();
  await expectReset(page);
  await page.getByRole('button', { name: '放大圖片', exact: true }).click();
  await viewport(page).evaluate(el => {
    for (const [type, x] of [['touchstart', 70], ['touchmove', 900], ['touchend', 900]] as const) {
      const point = { clientX: x, clientY: 250, identifier: 1 };
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperties(event, { touches: { value: type === 'touchend' ? [] : [point] }, changedTouches: { value: [point] } });
      el.dispatchEvent(event);
    }
  });
  const g = await geometry(page), b = (await points(page)).box;
  // offsetWidth is the CSS img box; portrait object-fit content may be narrower.
  const contentWidth = Math.min(g.baseWidth, g.baseHeight * .5);
  expect(Math.abs(g.x)).toBeLessThanOrEqual(Math.max(0, (contentWidth * g.scale - b.width) / 2) + 2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  await expect(page.getByRole('button', { name: '原始大小', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: '關閉圖片檢視', exact: true })).toBeVisible();
  await page.getByRole('button', { name: '原始大小', exact: true }).click();
  await expectReset(page);
});

test('pinch finger removal and cancellation never become a slide swipe; route disposal resets zoom', async ({ page }) => {
  await openSyntheticProduct(page, 3);
  const originalOverflow = await page.evaluate(() => document.body.style.overflow);
  await page.getByTestId('product-gallery-open').click();
  const source = await image(page).getAttribute('src');
  // Deterministic TouchEvent transition regression supplements trusted CDP above.
  // Real touchcancel/finger-lift timing differs by platform, so explicitly exercise
  // both event shapes without reading any Vue component state.
  await viewport(page).evaluate(el => {
    const fire = (type: string, touches: { clientX: number; clientY: number; identifier: number }[], changed = touches) => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperties(event, { touches: { value: touches }, changedTouches: { value: changed } });
      el.dispatchEvent(event);
    };
    const left = { clientX: 130, clientY: 250, identifier: 1 }, right = { clientX: 200, clientY: 250, identifier: 2 };
    fire('touchstart', [left, right]);
    fire('touchmove', [{ ...left, clientX: 60 }, { ...right, clientX: 270 }]);
    fire('touchend', [{ ...left, clientX: 60 }], [{ ...right, clientX: 270 }]);
    fire('touchmove', [{ ...left, clientX: 250 }]);
    fire('touchcancel', [], [{ ...left, clientX: 250 }]);
    fire('touchend', [], [{ ...left, clientX: 250 }]);
  });
  await expect(image(page)).toHaveAttribute('src', source!);
  expect((await geometry(page)).scale).toBeGreaterThan(1);
  await page.goBack();
  await expect(page).toHaveURL(/\/food$/);
  await expect.poll(() => page.evaluate(() => document.body.style.overflow)).toBe(originalOverflow);
  await page.locator('a[href="/food/993"]').click();
  await page.getByTestId('product-gallery-open').click();
  await expectReset(page);
});


