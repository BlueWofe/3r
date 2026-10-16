import { expect, test } from '@playwright/test';
import { openSyntheticProduct } from './fixtures/product-gallery';

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
      await expect(page.locator('.product-layout img:visible')).toHaveCount(count + 1);
      const boxes = await page.locator('.product-layout img:visible').evaluateAll(images => images.map(image => {
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
      const buttonBoxes = await page.locator('.gallery-thumbnail').evaluateAll(buttons => buttons.map(button => ({ height: button.getBoundingClientRect().height, imageHeight: button.querySelector('img')!.getBoundingClientRect().height })));
      expect(buttonBoxes.every(box => Math.abs(box.height - box.imageHeight) <= 1)).toBeTruthy();
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

test('missing main image promotes the first gallery image without losing the others', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 950 });
  await openSyntheticProduct(page, 3, false);
  await expect(page.locator('.product-detail-image')).toHaveAttribute('src', /\/files\/9910\/download$/);
  await expect(page.locator('.product-gallery-image')).toHaveCount(3);
  const main = await page.locator('.product-detail-image').boundingBox();
  const boxes = await page.locator('.product-gallery-image').evaluateAll(images => images.map(image => {
    const b = image.getBoundingClientRect();
    return { y: b.y, x: b.x, right: b.right };
  }));
  for (const box of boxes) {
    expect(box.y).toBeGreaterThanOrEqual(main!.y + main!.height);
    expect(box.x).toBeGreaterThanOrEqual(main!.x - 1);
    expect(box.right).toBeLessThanOrEqual(main!.x + main!.width + 1);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
});
