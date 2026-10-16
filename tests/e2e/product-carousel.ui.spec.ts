import { chromium, expect, test, type Locator, type Page } from '@playwright/test';
import { openSyntheticProduct } from './fixtures/product-gallery';

// Every browser API request, including image, quote and guest endpoints, is mocked.
// No live account, application database or uploaded image is changed by this suite.
const main = (page: Page) => page.getByTestId('product-gallery-main-image');
const dialog = (page: Page) => page.getByTestId('product-lightbox');
const large = (page: Page) => page.getByTestId('product-lightbox-image');
const photo = (id: number) => new RegExp(`/files/${id}/download$`);

async function swipe(page: Page, target: Locator, direction: 'left' | 'right' | 'vertical') {
  await target.scrollIntoViewIfNeeded();
  const b = await target.boundingBox();
  expect(b).not.toBeNull();
  const x = b!.x + b!.width * (direction === 'right' ? .2 : .8);
  const y = b!.y + b!.height * .5;
  const endX = direction === 'vertical' ? x : b!.x + b!.width * (direction === 'right' ? .8 : .2);
  const endY = direction === 'vertical' ? y + Math.min(90, b!.height * .35) : y;
  // Real Chromium touch pipeline (pointer/touch events + browser gesture recognition),
  // not dispatchEvent(), so compatibility clicks and touch-action are exercised.
  const client = await page.context().newCDPSession(page);
  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y, id: 1 }] });
  for (let step = 1; step <= 6; step++) {
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + (endX - x) * step / 6, y: y + (endY - y) * step / 6, id: 1 }] });
  }
  await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await client.detach();
}

test('thumbnail and circular controls select photos without disturbing quote and cart', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openSyntheticProduct(page, 3);
  await expect(main(page)).toHaveAttribute('src', photo(9900));
  await expect(page.getByTestId('product-gallery-count')).toHaveText('1 / 4');
  await page.getByTestId('product-gallery-thumbnail-2').click();
  await expect(main(page)).toHaveAttribute('src', photo(9911));
  await expect(page.getByTestId('product-gallery-count')).toHaveText('3 / 4');
  await page.getByRole('button', { name: '下一張圖片', exact: true }).click();
  await expect(main(page)).toHaveAttribute('src', photo(9912));
  await page.getByRole('button', { name: '下一張圖片', exact: true }).click();
  await expect(main(page)).toHaveAttribute('src', photo(9900));
  await page.getByRole('button', { name: '上一張圖片', exact: true }).click();
  await expect(main(page)).toHaveAttribute('src', photo(9912));
  await page.getByLabel('數量').fill('2');
  await page.getByRole('button', { name: '查詢報價', exact: true }).click();
  await expect(page.locator('.notice').filter({ hasText: '合計 200' })).toBeVisible();
  await page.getByRole('button', { name: '加入購物車', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: '已加入購物車' })).toBeVisible();
  expect(errors).toEqual([]);
});

test('lightbox synchronizes index, traps keyboard focus and restores focus and scrolling', async ({ page }) => {
  await openSyntheticProduct(page, 3);
  await page.getByTestId('product-gallery-thumbnail-1').click();
  const opener = page.getByTestId('product-gallery-open');
  await opener.scrollIntoViewIfNeeded();
  const before = await page.evaluate(() => ({ y: scrollY, overflow: document.body.style.overflow }));
  await opener.click();
  await expect(dialog(page)).toBeVisible();
  await expect(large(page)).toHaveAttribute('src', photo(9910));
  await expect(page.getByTestId('product-lightbox-count')).toContainText('2 / 4');
  await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
  await page.keyboard.press('ArrowRight');
  await expect(large(page)).toHaveAttribute('src', photo(9911));
  await page.getByRole('button', { name: '下一張大圖', exact: true }).click();
  await expect(large(page)).toHaveAttribute('src', photo(9912));
  await page.keyboard.press('ArrowRight');
  await expect(large(page)).toHaveAttribute('src', photo(9900));
  await page.keyboard.press('ArrowLeft');
  await expect(large(page)).toHaveAttribute('src', photo(9912));
  await page.getByRole('button', { name: '上一張大圖', exact: true }).click();
  await expect(large(page)).toHaveAttribute('src', photo(9911));
  for (let n = 0; n < 7; n++) {
    await page.keyboard.press('Tab');
    expect(await dialog(page).evaluate(el => el.contains(document.activeElement))).toBeTruthy();
  }
  await page.keyboard.press('Shift+Tab');
  expect(await dialog(page).evaluate(el => el.contains(document.activeElement))).toBeTruthy();
  await page.keyboard.press('Escape');
  await expect(dialog(page)).not.toBeVisible();
  await expect(main(page)).toHaveAttribute('src', photo(9911));
  await expect(opener).toBeFocused();
  const after = await page.evaluate(() => ({ y: scrollY, overflow: document.body.style.overflow }));
  expect(after.overflow).toBe(before.overflow);
  expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(2);
  await opener.click();
  await page.getByRole('button', { name: '關閉圖片檢視', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  await expect(opener).toBeFocused();
});

test('horizontal touch swipes gallery and lightbox while vertical gestures preserve photo', async ({ isMobile, baseURL }) => {
  test.skip(!isMobile, 'Desktop interaction uses arrows/keyboard; touch gestures are exercised in the mobile project.');
  // The mobile project uses WebKit. Launch a dedicated Chromium mobile context
  // for trusted CDP touch gestures; the remaining mobile assertions stay WebKit.
  const touchBrowser = await chromium.launch();
  const context = await touchBrowser.newContext({ baseURL, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
  const page = await context.newPage();
  try {
    await openSyntheticProduct(page, 3);
    // Open with a genuine mobile tap before injecting CDP gestures. Reusing
    // Playwright touchscreen.tap after CDP gestures can omit the browser's
    // compatibility click; the application never receives that click.
    await page.getByTestId('product-gallery-open').tap();
    await expect(dialog(page)).toBeVisible();
    await swipe(page, large(page), 'left');
    await expect(large(page)).toHaveAttribute('src', photo(9910));
    await swipe(page, large(page), 'vertical');
    await expect(large(page)).toHaveAttribute('src', photo(9910));
    await swipe(page, large(page), 'right');
    await expect(large(page)).toHaveAttribute('src', photo(9900));
    await expect(dialog(page)).toBeVisible();
    await page.getByRole('button', { name: '關閉圖片檢視', exact: true }).click();
    await expect(dialog(page)).not.toBeVisible();
    await swipe(page, main(page), 'left');
    await expect(main(page)).toHaveAttribute('src', photo(9910));
    await expect(dialog(page)).not.toBeVisible();
    await swipe(page, main(page), 'vertical');
    await expect(main(page)).toHaveAttribute('src', photo(9910));
    await expect(dialog(page)).not.toBeVisible();
    await swipe(page, main(page), 'right');
    await expect(main(page)).toHaveAttribute('src', photo(9900));
    await expect(dialog(page)).not.toBeVisible();
  } finally { await touchBrowser.close(); }
});

test('single image can enlarge without redundant navigation', async ({ page }) => {
  await openSyntheticProduct(page, 0);
  await expect(main(page)).toHaveAttribute('src', photo(9900));
  await expect(page.getByRole('button', { name: '下一張圖片', exact: true })).toHaveCount(0);
  await expect(page.getByTestId('product-gallery-thumbnails')).toHaveCount(0);
  await page.getByTestId('product-gallery-open').click();
  await expect(large(page)).toHaveAttribute('src', photo(9900));
  await expect(page.getByRole('button', { name: '下一張大圖', exact: true })).toHaveCount(0);
  await page.keyboard.press('ArrowRight');
  await expect(large(page)).toHaveAttribute('src', photo(9900));
  await page.keyboard.press('Escape');
  await expect(dialog(page)).not.toBeVisible();
});

test('zero images retain illustration without opening an empty lightbox', async ({ page }) => {
  await openSyntheticProduct(page, 0, false);
  await expect(page.locator('.product-layout .pastry-illustration')).toBeVisible();
  await expect(page.getByTestId('product-gallery-open')).toHaveCount(0);
  await expect(main(page)).toHaveCount(0);
  await expect(dialog(page)).not.toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
});

test('duplicate main and gallery IDs do not create duplicate slides', async ({ page }) => {
  await openSyntheticProduct(page, 4, true, [9900, 9910, 9910, 9911]);
  await expect(page.getByTestId('product-gallery-count')).toHaveText('1 / 3');
  await expect(page.locator('.product-gallery-image')).toHaveCount(2);
  await page.getByRole('button', { name: '上一張圖片', exact: true }).click();
  await expect(main(page)).toHaveAttribute('src', photo(9911));
  await page.getByRole('button', { name: '下一張圖片', exact: true }).click();
  await expect(main(page)).toHaveAttribute('src', photo(9900));
});


test('navigating away from an open lightbox restores page scroll', async ({ page }) => {
  await openSyntheticProduct(page, 3);
  const overflow = await page.evaluate(() => document.body.style.overflow);
  await page.getByTestId('product-gallery-open').click();
  await expect(dialog(page)).toBeVisible();
  await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
  await page.goBack();
  await expect(page).toHaveURL(/\/food$/);
  await expect(page.getByTestId('product-gallery')).toHaveCount(0);
  expect(await page.evaluate(() => document.body.style.overflow)).toBe(overflow);
  await page.locator('a[href="/food/993"]').click();
  await expect(main(page)).toHaveAttribute('src', photo(9900));
  await expect(dialog(page)).not.toBeVisible();
});

test('fresh touch gesture accepts its intentional click after suppressing a swipe compatibility click', async ({ page }) => {
  await openSyntheticProduct(page, 3);
  const opener = page.getByTestId('product-gallery-open');
  // Deterministic application-event regression, deliberately separate from the
  // trusted Chromium touch integration above. No browser gesture timing is assumed.
  await opener.evaluate(el => {
    const fire = (type: string, x: number, ending = false) => {
      const point = { clientX: x, clientY: 50 };
      const event = new Event(type, { bubbles: true });
      Object.defineProperties(event, {
        touches: { value: ending ? [] : [point] },
        changedTouches: { value: [point] },
      });
      el.dispatchEvent(event);
    };
    fire('touchstart', 200);
    fire('touchmove', 80);
    fire('touchend', 80, true);
    (el as HTMLButtonElement).click();
  });
  await expect(main(page)).toHaveAttribute('src', photo(9910));
  await expect(dialog(page)).not.toBeVisible();
  await opener.evaluate(el => {
    const point = { clientX: 100, clientY: 50 };
    for (const type of ['touchstart', 'touchend']) {
      const event = new Event(type, { bubbles: true });
      Object.defineProperties(event, {
        touches: { value: type === 'touchend' ? [] : [point] },
        changedTouches: { value: [point] },
      });
      el.dispatchEvent(event);
    }
    (el as HTMLButtonElement).click();
  });
  await expect(dialog(page)).toBeVisible();
  await expect(large(page)).toHaveAttribute('src', photo(9910));
});
