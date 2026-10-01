import { expect, test } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

type Product = {
  id: number;
  version: number;
  metadata: { variants: { id: string; stock: number }[] };
};
type OrderSummary = { id: number; order_number: string; customer_name: string; status: string; version: number; total: number };

test('guest can quote and submit once; an authorized manager can read, cancel, and restore stock', async () => {
  const admin = await apiContext();
  const guest = await apiContext();
  let productId: number | undefined;
  let orderId: number | undefined;
  let customerName: string | undefined;

  try {
    await login(admin, '0900000001');

    // Use current shipping settings without modifying shared settings. Pickup is
    // a safe fallback when delivery is disabled in a test environment.
    const shipping = await json<{
      shipping_enabled: boolean;
      pickup_enabled: boolean;
    }>(await guest.get('/api/v1/public/shipping-settings'));
    const deliveryMethod = shipping.shipping_enabled ? 'shipping' : 'pickup';
    expect(deliveryMethod === 'shipping' || shipping.pickup_enabled, 'at least one delivery option is enabled').toBeTruthy();

    const suffix = unique('guest-order').toLowerCase();
    const variantId = `variant-${suffix}`;
    const product = await json<Product>(await mutate(admin, 'post', '/api/v1/products', {
      title: `E2E 訪客訂單 ${suffix}`,
      slug: suffix,
      body: '訂單流程合成資料。',
      summary: '只供隔離 CI 驗收。',
      category: `E2E 訂單 ${suffix}`,
      status: 'published',
      sort_order: 0,
      metadata: {
        unit: '盒', currency: 'TWD', gallery_ids: [], spec_axes: [],
        variants: [{ id: variantId, sku: `SKU-${suffix}`, options: [], price: 137, stock: 7, active: true, wholesale: [] }],
      },
    }));
    productId = product.id;
    expect(product.metadata.variants[0].stock).toBe(7);

    const cart = {
      delivery_method: deliveryMethod,
      items: [{ product_id: product.id, variant_id: variantId, quantity: 2 }],
    };
    const quote = await json<{
      items: { title: string; quantity: number; unit_price: number }[];
      subtotal: number;
      shipping_fee: number;
      total: number;
      total_cents: number;
      currency: string;
    }>(await mutate(guest, 'post', '/api/v1/public/orders/quote', cart));
    expect(quote).toMatchObject({ subtotal: 274, currency: 'TWD' });
    expect(quote.items).toMatchObject([{ title: `E2E 訪客訂單 ${suffix}`, quantity: 2, unit_price: 137 }]);

    customerName = `CI 訪客 ${suffix}`;
    const submission = {
      ...cart,
      customer_name: customerName,
      customer_phone: '0900000000',
      ...(deliveryMethod === 'shipping' ? { address: 'CI 合成地址' } : {}),
      idempotency_key: crypto.randomUUID(),
      expected_total_cents: quote.total_cents,
    };
    const confirmation = await json<{ order_number: string; status: string; total: number; currency: string }>(
      await mutate(guest, 'post', '/api/v1/public/orders', submission),
    );
    expect(confirmation).toMatchObject({ status: 'new', total: quote.total, currency: 'TWD' });
    expect(confirmation.order_number).toMatch(/^R3-\d{8}-/);

    const replay = await guest.post('/api/v1/public/orders', {
      data: submission,
      headers: { 'X-CSRF-TOKEN': await (await guest.get('/api/v1/auth/csrf')).json().then(body => body.csrf_token as string) },
    });
    expect(replay.status()).toBe(200);
    expect(await replay.json()).toEqual(confirmation);

    const stockAfterOrder = await json<Product>(await admin.get(`/api/v1/products/${product.id}`));
    expect(stockAfterOrder.metadata.variants[0].stock).toBe(5);

    const orders = await json<{ data: OrderSummary[] }>(await admin.get(`/api/v1/orders?q=${encodeURIComponent(customerName)}`));
    orderId = orders.data[0]?.id;
    expect(orders.data).toHaveLength(1);
    expect(orders.data[0]).toMatchObject({
      order_number: confirmation.order_number,
      customer_name: customerName,
      status: 'new',
      version: 1,
      total: quote.total,
    });
    const detail = await json<OrderSummary & { items: { product_id: number; quantity: number }[] }>(
      await admin.get(`/api/v1/orders/${orderId}`),
    );
    expect(detail.items).toContainEqual(expect.objectContaining({ product_id: product.id, quantity: 2 }));

    const cancelled = await json<OrderSummary>(await mutate(admin, 'put', `/api/v1/orders/${orderId}`, {
      version: detail.version,
      status: 'cancelled',
    }));
    expect(cancelled).toMatchObject({ status: 'cancelled', version: 2 });
    const restoredProduct = await json<Product>(await admin.get(`/api/v1/products/${product.id}`));
    expect(restoredProduct.metadata.variants[0].stock).toBe(7);

    const replayAfterCancel = await guest.post('/api/v1/public/orders', {
      data: submission,
      headers: { 'X-CSRF-TOKEN': await (await guest.get('/api/v1/auth/csrf')).json().then(body => body.csrf_token as string) },
    });
    expect(replayAfterCancel.status()).toBe(200);
    expect(await replayAfterCancel.json()).toEqual({ ...confirmation, status: 'cancelled' });
    expect((await json<Product>(await admin.get(`/api/v1/products/${product.id}`))).metadata.variants[0].stock).toBe(7);
  } finally {
    if (!orderId && customerName) {
      const existing = await admin.get(`/api/v1/orders?q=${encodeURIComponent(customerName)}`);
      if (existing.ok()) {
        const { data } = await existing.json() as { data: OrderSummary[] };
        orderId = data[0]?.id;
      }
    }
    if (orderId) {
      const existing = await admin.get(`/api/v1/orders/${orderId}`);
      if (existing.ok()) {
        const order = await existing.json() as OrderSummary;
        if (order.status !== 'cancelled') {
          await mutate(admin, 'put', `/api/v1/orders/${orderId}`, { version: order.version, status: 'cancelled' });
        }
      }
    }
    if (productId) {
      const removed = await mutate(admin, 'delete', `/api/v1/products/${productId}`);
      expect([200, 204, 404]).toContain(removed.status());
    }
    await Promise.all([admin.dispose(), guest.dispose()]);
  }
});
