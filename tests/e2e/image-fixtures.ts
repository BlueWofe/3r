import { expect, type Locator, type Page } from '@playwright/test';

export async function createTallPng(page: Page, color: string) {
  const dataUrl = await page.evaluate((fill) => {
    const canvas = document.createElement('canvas');
    canvas.width = 80;
    canvas.height = 1600;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Canvas 2D context is unavailable');
    context.fillStyle = fill;
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#ffffff';
    context.fillRect(8, 30, 64, 18);
    context.fillRect(8, 1540, 64, 18);
    return canvas.toDataURL('image/png');
  }, color);
  const base64 = dataUrl.split(',')[1];
  if (!base64) throw new Error('Canvas did not produce PNG data');
  return Buffer.from(base64, 'base64');
}

export async function expectTallImageWithin(image: Locator, maxHeight: number) {
  await expect.poll(() => image.evaluate(element => {
    const img = element as HTMLImageElement;
    return {
      complete: img.complete,
      naturalWidth: img.naturalWidth,
      naturalHeight: img.naturalHeight,
    };
  })).toMatchObject({ complete: true, naturalWidth: 80, naturalHeight: 1600 });
  const renderedHeight = await image.evaluate(element => element.getBoundingClientRect().height);
  expect(renderedHeight).toBeLessThanOrEqual(maxHeight + 1);
}
