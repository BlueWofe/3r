import { test, expect } from '@playwright/test';
import { apiContext, json, login, mutate, unique } from './helpers';

function taipeiDate(offset = 0) {
  const date = new Date();
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  const local = new Date(Date.UTC(
    Number(parts.find(p => p.type === 'year')!.value),
    Number(parts.find(p => p.type === 'month')!.value) - 1,
    Number(parts.find(p => p.type === 'day')!.value) + offset,
  ));
  return local.toISOString().slice(0, 10);
}

function dateOffset(from: string, offset: number) {
  const [year, month, day] = from.split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day + offset)).toISOString().slice(0, 10);
}

function weekdayOfFifthOccurrence(from: string, throughDays = 90) {
  // Find a weekday whose fifth occurrence falls inside the preview window.
  for (let weekday = 1; weekday <= 7; weekday++) {
    const seen = new Map<string, number>();
    for (let offset = 0; offset <= throughDays; offset++) {
      const date = new Date(`${dateOffset(from, offset)}T12:00:00+08:00`);
      const current = date.getDay() || 7;
      if (current !== weekday) continue;
      const month = `${date.getFullYear()}-${date.getMonth()}`;
      const ordinal = (seen.get(month) ?? 0) + 1;
      seen.set(month, ordinal);
      if (ordinal === 5) return weekday;
    }
  }
  throw new Error(`No fifth weekday in preview window starting ${from}`);
}

test('products validate variant ladders and quote server-side prices at quantity thresholds', async () => {
  const admin = await apiContext();
  try {
    await login(admin, '0900000001');
    const suffix = unique('商品規格').toLowerCase();
    const variantId = `box-${suffix}`;
    const product = await json<{ id: number; slug: string; metadata: Record<string, unknown> }>(await mutate(admin, 'post', '/api/v1/products', {
      title: 'E2E 多規格商品', slug: suffix, body: '合成資料', summary: '多規格與數量階梯', category: 'E2E', status: 'published', sort_order: 0,
      metadata: {
        unit: '盒', currency: 'TWD', gallery_ids: [],
        spec_axes: [{ name: '口味', options: ['原味', '可可'] }, { name: '包裝', options: ['6入'] }],
        variants: [
          { id: variantId, sku: `SKU-${suffix}`, options: ['原味', '6入'], price: 120, stock: 80, active: true, wholesale: [{ min_quantity: 5, unit_price: 110 }, { min_quantity: 12, unit_price: 95 }] },
          { id: `cocoa-${suffix}`, sku: `SKU-C-${suffix}`, options: ['可可', '6入'], price: 130, stock: 30, active: true, wholesale: [] },
        ],
      },
    }));
    expect(product.id).toBeTruthy();

    const publicProduct = await json<{ data: { metadata: { variants: { id: string }[] } } }>(await admin.get(`/api/v1/public/products/${product.id}`));
    expect(publicProduct.data.metadata.variants.map(variant => variant.id)).toContain(variantId);

    const quote = async (quantity: number) => json<{ variant_id: string; quantity: number; unit_price: number; total: number; applied_min_quantity: number | null; currency: string; stock: number }>(
      await admin.get(`/api/v1/public/products/${product.id}/quote?variant_id=${encodeURIComponent(variantId)}&quantity=${quantity}`),
    );
    const single = await quote(1);
    expect(single).toMatchObject({ variant_id: variantId, quantity: 1, unit_price: 120, total: 120, currency: 'TWD', stock: 80 });
    const firstTier = await quote(5);
    expect(firstTier).toMatchObject({ unit_price: 110, total: 550, applied_min_quantity: 5 });
    const upperTier = await quote(12);
    expect(upperTier).toMatchObject({ unit_price: 95, total: 1140, applied_min_quantity: 12 });
    expect((await admin.get(`/api/v1/public/products/${product.id}/quote?variant_id=${variantId}&quantity=0`)).ok()).toBeFalsy();
    expect((await admin.get(`/api/v1/public/products/${product.id}/quote?variant_id=${variantId}&quantity=81`)).ok()).toBeFalsy();

    const invalidLadder = await mutate(admin, 'post', '/api/v1/products', {
      title: 'E2E 非法折扣不得儲存', slug: unique('invalid-discount').toLowerCase(), body: '合成資料', summary: '', category: 'E2E', status: 'published', sort_order: 0,
      metadata: { currency: 'TWD', variants: [{ id: unique('variant'), sku: unique('sku'), options: [], price: 100, stock: 10, active: true, wholesale: [{ min_quantity: 10, unit_price: 90 }, { min_quantity: 5, unit_price: 80 }] }] },
    });
    expect(invalidLadder.status(), `invalid discount returned ${invalidLadder.status()}: ${await invalidLadder.text()}`).toBe(422);

    const draftVariantId = unique('draft-variant');
    const draft = await json<{ id: number }>(await mutate(admin, 'post', '/api/v1/products', {
      title: 'E2E 草稿不可報價', slug: unique('draft-product').toLowerCase(), body: '草稿', summary: '', category: 'E2E', status: 'draft', sort_order: 0,
      metadata: { currency: 'TWD', variants: [{ id: draftVariantId, sku: unique('draft-sku'), options: [], price: 10, stock: 2, active: true, wholesale: [] }] },
    }));
    const draftQuote = await admin.get(`/api/v1/public/products/${draft.id}/quote?variant_id=${encodeURIComponent(draftVariantId)}&quantity=1`);
    expect(draftQuote.ok(), 'draft products must not be publicly quotable').toBeFalsy();
  } finally {
    await admin.dispose();
  }
});

test('class preview covers weekly, day 31 and nth weekdays; generation is idempotent and preserves edits', async () => {
  const admin = await apiContext();
  const teacher = await apiContext();
  try {
    await login(admin, '0900000001');
    await login(teacher, '0900000002');

    const startDate = taipeiDate();
    const monthlyWeekday = weekdayOfFifthOccurrence(startDate);
    const suffix = unique('E2E 班別');
    const rules = [
      { id: `weekly-${suffix}`, frequency: 'weekly', weekdays: [2], start_time: '09:00', end_time: '10:00' },
      { id: `day31-${suffix}`, frequency: 'monthly_date', month_day: 31, start_time: '10:00', end_time: '11:00' },
      { id: `fifth-${suffix}`, frequency: 'monthly_weekday', week_of_month: 5, weekday: monthlyWeekday, start_time: '11:00', end_time: '12:00' },
      { id: `last-${suffix}`, frequency: 'monthly_weekday', week_of_month: -1, weekday: 7, start_time: '13:00', end_time: '14:00' },
    ];
    const payload = {
      name: suffix, prison: 'E2E 合成場域', location: '測試教室', participant_count: 0,
      teacher_ids: [], active: true, start_date: startDate, end_date: null, rules,
    };
    const preview = await json<{ data: { rule_id: string; service_date: string; start_time: string; end_time: string }[]; skipped: unknown[]; through: string }>(
      await mutate(admin, 'post', '/api/v1/class-templates/preview', payload),
    );
    expect(preview.data.some(row => row.rule_id === rules[0].id)).toBeTruthy();
    expect(preview.data.some(row => row.rule_id === rules[1].id && row.service_date.endsWith('-31'))).toBeTruthy();
    expect(preview.data.some(row => row.rule_id === rules[2].id)).toBeTruthy();
    expect(preview.data.some(row => row.rule_id === rules[3].id)).toBeTruthy();
    expect(preview.through >= startDate).toBeTruthy();

    const template = await json<{ id: number; version: number }>(await mutate(admin, 'post', '/api/v1/class-templates', payload));
    const deniedRead = await teacher.get('/api/v1/class-templates');
    expect(deniedRead.status()).toBe(403);
    const deniedManage = await mutate(teacher, 'post', '/api/v1/class-templates/preview', payload);
    expect(deniedManage.status()).toBe(403);
    const deniedCreate = await mutate(teacher, 'post', '/api/v1/class-templates', payload);
    expect(deniedCreate.status()).toBe(403);

    const first = await json<{ created: number; existing: number; skipped: { service_date: string; rule_id: string; reason: string }[]; through: string }>(
      await mutate(admin, 'post', `/api/v1/class-templates/${template.id}/generate`, { version: template.version }),
    );
    expect(first.created).toBeGreaterThan(0);
    const second = await json<typeof first>(await mutate(admin, 'post', `/api/v1/class-templates/${template.id}/generate`, { version: template.version }));
    expect(second.created).toBe(0);
    expect(second.existing).toBeGreaterThanOrEqual(first.created);

    const sessions = await json<{ data: ({ id: number; version: number; title: string; template_id?: number; class_template_id?: number; rule_id?: string; occurrence_date?: string })[] }>(
      await admin.get(`/api/v1/sessions?from=${startDate}&to=${first.through}`),
    );
    const generated = sessions.data.find(row => (row.template_id ?? row.class_template_id) === template.id && row.rule_id === rules[0].id);
    expect(generated, 'generated occurrence should keep its template/rule link').toBeTruthy();
    const teachers = await json<{ data: { id: number; phone: string }[] }>(await admin.get('/api/v1/users'));
    const teacherToAssign = teachers.data.find(row => row.phone === '0900000004');
    expect(teacherToAssign).toBeTruthy();
    const assign = await mutate(admin, 'post', `/api/v1/sessions/${generated!.id}/assign`, {
      version: generated!.version, teacher_id: teacherToAssign!.id, reason: 'E2E 將生成班別指派老師', override_conflict: true,
    });
    const assigned = await json<{ version: number; assignments: { teacher_id: number; status: string }[] }>(assign);
    expect(assigned.assignments.some(row => row.teacher_id === teacherToAssign!.id && row.status === 'assigned')).toBeTruthy();
    const deniedAssignment = await mutate(teacher, 'post', `/api/v1/sessions/${generated!.id}/assign`, {
      version: assigned.version, teacher_id: teacherToAssign!.id, reason: '教師不得管理其他場次指派',
    });
    expect(deniedAssignment.status()).toBe(403);

    const third = await json<typeof first>(await mutate(admin, 'post', `/api/v1/class-templates/${template.id}/generate`, { version: template.version }));
    expect(third.created).toBe(0);
    const after = await json<{ assignments: { teacher_id: number; status: string }[] }>(await admin.get(`/api/v1/sessions/${generated!.id}`));
    expect(after.assignments.some(row => row.teacher_id === teacherToAssign!.id && row.status === 'assigned')).toBeTruthy();
  } finally {
    await Promise.all([admin.dispose(), teacher.dispose()]);
  }
});
