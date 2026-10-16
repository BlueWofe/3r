import { expect, test, type Page } from '@playwright/test';

// Figma UOLMCw50qbCQFpLVG66f2L: desktop 14:18265 / gallery 14:18266.
// Mobile 5:1358 has only a main photo: the thumbnail row is a responsive
// adaptation that preserves the live product's additional photos.
// All browser API calls are synthetic; no application data is changed.
for (const width of [320, 390, 1440]) {
  for (const count of [0, 1, 3, 10]) {
    test(`product gallery stays below its main image: ${count} photos at ${width}px`, async ({ page }, testInfo) => {
      const failures: string[] = [];
      page.on('pageerror', error => failures.push(error.message));
      await page.setViewportSize({ width, height: 950 });
      await openSyntheticProduct(page, count);

      const main = page.locator('.product-detail-image');
      const thumbnails = page.locator('.product-gallery-image');
      await expect(main).toBeVisible();
      await expect(thumbnails).toHaveCount(count);
      await expect(page.locator('.product-layout img')).toHaveCount(count + 1);
      const boxes = await page.locator('.product-layout img').evaluateAll(images => images.map(image => {
        const box = image.getBoundingClientRect();
        const img = image as HTMLImageElement;
        return { x: box.x, y: box.y, right: box.right, bottom: box.bottom, height: box.height,
          loaded: img.complete && img.naturalWidth > 0, downloadable: !!img.closest('a[download], a[href*="/download"]') };
      }));
      expect(boxes.every(box => box.loaded)).toBeTruthy();
      expect(boxes.every(box => !box.downloadable)).toBeTruthy();
      const hero = boxes[0];
      expect(hero.height).toBeLessThanOrEqual(width < 768 ? 280 : 420);
      for (const thumbnail of boxes.slice(1)) {
        expect(thumbnail.y, 'additional photos must sit below the main image').toBeGreaterThanOrEqual(hero.bottom);
        expect(thumbnail.x).toBeGreaterThanOrEqual(hero.x - 1);
        expect(thumbnail.right).toBeLessThanOrEqual(hero.right + 1);
      }
      if (count === 3) {
        expect(Math.max(...boxes.slice(1).map(box => box.y)) - Math.min(...boxes.slice(1).map(box => box.y)),
          'the three supporting thumbnails must share one row on desktop and phone').toBeLessThanOrEqual(1);
      }
      const info = await page.locator('.product-layout article').boundingBox();
      await testInfo.attach('product-gallery-geometry', { body: JSON.stringify({ width, count, images: boxes, article: info }), contentType: 'application/json' });
      expect(info).not.toBeNull();
      if (width >= 1024) {
        expect(info!.x, 'desktop product information belongs in the right column').toBeGreaterThanOrEqual(hero.right);
      } else {
        expect(info!.y, 'phone product information follows the entire photo gallery').toBeGreaterThanOrEqual(Math.max(...boxes.map(box => box.bottom)));
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
      await page.getByLabel('數量').fill('2');
      await page.getByRole('button', { name: '查詢報價' }).click();
      await expect(page.locator('.notice').filter({ hasText: '合計 200' })).toBeVisible();
      await page.getByRole('button', { name: '加入購物車', exact: true }).click();
      await expect(page.getByRole('status').filter({ hasText: '已加入購物車' })).toBeVisible();
      expect(failures).toEqual([]);
    });
  }
}

test('missing main image retains pastry fallback and additional photos', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 950 });
  await openSyntheticProduct(page, 3, false);
  await expect(page.locator('.product-detail-image')).toHaveCount(0);
  await expect(page.locator('.product-layout .pastry-illustration')).toBeVisible();
  await expect(page.locator('.product-gallery-image')).toHaveCount(3);
  const fallback = await page.locator('.product-layout .pastry-illustration').boundingBox();
  const photo = await page.locator('.product-gallery-image').first().boundingBox();
  expect(photo!.y).toBeGreaterThanOrEqual(fallback!.y + fallback!.height);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
});

async function openSyntheticProduct(page: Page, galleryCount: number, mainImage = true) {
  const product = {
    id: 993, kind: 'product', slug: 'classic-yolk-pastry', title: '合成圖庫測試商品',
    body: '專供圖片定位驗收，並非實際販售商品。', summary: '合成驗收資料',
    image_id: mainImage ? 9900 : null,
    metadata: { gallery_ids: Array.from({ length: galleryCount }, (_, index) => 9910 + index),
      variants: [{ id: 'synthetic-box', options: ['六入'], active: true, stock: 20, price: 100 }] },
  };
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
    if (/^\/files\/\d+\/download$/.test(path)) return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#739b77"/></svg>' });
    let result: unknown = { data: [] };
    if (path === '/auth/me') return route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } });
    if (path === '/public/products') result = { data: [product], categories: [] };
    if (path === '/public/products/993') result = { data: product };
    if (path === '/public/products/993/quote') result = { unit_price: 100, total: 200, currency: 'TWD' };
    if (path === '/public/contact') result = { data: { association_name: '合成驗收協會' } };
    return route.fulfill({ json: result });
  });
  // Nuxt SSR fetches are server-side. Enter the fixture by client navigation so
  // page.route intercepts the complete product payload and all image requests.
  await page.goto('/');
  await page.waitForFunction(() => !!(document.querySelector('#__nuxt') as any)?.__vue_app__);
  await page.waitForFunction(() => !(document.querySelector('#__nuxt') as any).__vue_app__.config.globalProperties.$nuxt.isHydrating);
  await page.locator('a[href="/food"]:visible').first().click();
  await page.locator('a[href="/food/993"]').click();
  await expect(page.locator('.product-layout')).toBeVisible();
}
