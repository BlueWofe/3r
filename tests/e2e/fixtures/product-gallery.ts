import { expect, type Page } from '@playwright/test';

export async function openSyntheticProduct(page: Page, galleryCount: number, mainImage = true, galleryIds?: number[], imageSize = { width: 800, height: 600 }) {
  const product = {
    id: 993, kind: 'product', slug: 'classic-yolk-pastry', title: '合成圖庫測試商品',
    body: '專供圖片定位驗收，並非實際販售商品。', summary: '合成驗收資料',
    image_id: mainImage ? 9900 : null,
    metadata: { gallery_ids: galleryIds ?? Array.from({ length: galleryCount }, (_, index) => 9910 + index),
      variants: [{ id: 'synthetic-box', options: ['六入'], active: true, stock: 20, price: 100 }] },
  };
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
    if (/^\/files\/\d+\/download$/.test(path)) return route.fulfill({ contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="${imageSize.width}" height="${imageSize.height}"><rect width="100%" height="100%" fill="#739b77"/></svg>` });
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
