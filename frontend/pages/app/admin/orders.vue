<script setup lang="ts">
definePageMeta({ layout: 'app' });
const { can } = useAuth();
const route = useRoute();
const rows = ref<any[]>([]), linkedOrder = ref<any>(null), loading = ref(false), error = ref(''), filter = ref(''), query = ref(''), saved = ref(''), focusedOrderId = ref<number | null>(null);
const selected = ref<any>(null), busy = ref(false), status = ref('new'), staffNote = ref('');
const statuses: Record<string,string> = { new: '新訂單', confirmed: '已確認', shipped: '已出貨', completed: '已完成', cancelled: '已取消' };
const transitions: Record<string,string[]> = { new: ['new','confirmed','cancelled'], confirmed: ['confirmed','shipped','completed','cancelled'], shipped: ['shipped','completed'], completed: ['completed'], cancelled: ['cancelled'] };
const availableStatuses = computed(() => (transitions[selected.value?.status] || []).map(key => ({ key, label: statuses[key] })));
const visible = computed(() => {
  const matches = rows.value.filter(x => (!filter.value || x.status === filter.value) && (!query.value || `${x.order_number} ${x.customer_name} ${x.customer_phone}`.includes(query.value)));
  return linkedOrder.value && !matches.some(order => order.id === linkedOrder.value.id) ? [linkedOrder.value, ...matches] : matches;
});
const money = (value: number) => new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(value);
const date = (value: string) => new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' });
let loadId = 0;
async function scrollToFocusedOrder() { await nextTick(); if (focusedOrderId.value) document.querySelector(`[data-order-id="${focusedOrderId.value}"]`)?.scrollIntoView({ block: 'center' }); }
async function load() { const id = ++loadId; loading.value = true; error.value = ''; try { const result = await api<any>(`/orders?status=${encodeURIComponent(filter.value)}&q=${encodeURIComponent(query.value)}`); if (id === loadId) rows.value = result.data || []; } catch(e: any) { if (id === loadId) { rows.value = []; linkedOrder.value = null; focusedOrderId.value = null; selected.value = null; error.value = e.message; } } finally { if (id === loadId) { loading.value = false; await scrollToFocusedOrder(); } } }
let filterTimer: ReturnType<typeof setTimeout>;
watch([filter, query], () => { clearTimeout(filterTimer); filterTimer = setTimeout(load, 250); });
onBeforeUnmount(() => clearTimeout(filterTimer));
let detailRequestId = 0;
async function open(order: any) { error.value = ''; saved.value = ''; const id = ++detailRequestId; selected.value = null; try { const detail = await api<any>(`/orders/${order.id}`); if (id !== detailRequestId) return; selected.value = detail; status.value = detail.status; staffNote.value = detail.staff_note || ''; } catch(e: any) { if (id === detailRequestId) error.value = e.message; } }
async function save() { if (!selected.value || busy.value) return; busy.value = true; error.value = ''; try { selected.value = await api<any>(`/orders/${selected.value.id}`, { method: 'PUT', body: { version: selected.value.version, status: status.value, staff_note: staffNote.value } }); await load(); saved.value = '訂單狀態與備註已儲存。'; } catch(e: any) { if(e.code === 409) await open(selected.value); error.value = e.message; } finally { busy.value = false; } }
async function focusLinkedOrder() {
  focusedOrderId.value = null;
  linkedOrder.value = null;
  const raw = route.query.id;
  if (raw === undefined) return;
  detailRequestId++; selected.value = null;
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) { error.value = '通知指定的訂單編號無效。'; return; }
  filter.value = ''; query.value = '';
  await load();
  try {
    if (!rows.value.some(order => Number(order.id) === id)) linkedOrder.value = await api<any>(`/orders/${id}`);
    focusedOrderId.value = id;
    await scrollToFocusedOrder();
  } catch (e: any) { focusedOrderId.value = null; error.value = e.message || '找不到指定的訂單。'; }
}
watch(() => route.query.id, focusLinkedOrder);
onMounted(async () => { await load(); await focusLinkedOrder(); });
</script>
<template>
  <div class="workhead"><div><p class="eyebrow">ORDER MANAGEMENT</p><h1>訂單管理</h1><p class="muted">查看顧客訂單、配送資料與處理進度。每次顯示最新 500 筆符合條件的訂單。本系統不收取線上付款。</p></div><button class="button ghost" :disabled="loading" @click="load">重新整理</button></div>
  <p v-if="error" class="error" role="alert">{{ error }}</p>
  <div class="order-filters"><label class="field">搜尋訂單<input v-model.trim="query" placeholder="訂單編號、姓名或電話" /></label><label class="field">處理狀態<select v-model="filter"><option value="">全部狀態</option><option v-for="(label,key) in statuses" :key="key" :value="key">{{ label }}</option></select></label></div>
  <p v-if="loading" class="empty">載入訂單中…</p><p v-else-if="!visible.length" class="empty">目前沒有符合條件的訂單。</p>
  <div v-else class="order-list"><article v-for="order in visible" :key="order.id" class="card order-card" :class="{ 'notification-focus': focusedOrderId === order.id }" :data-order-id="order.id"><div class="order-heading"><strong>{{ order.order_number }}</strong><span class="status">{{ statuses[order.status] || order.status }}</span></div><p>{{ order.customer_name }} · <a :href="`tel:${order.customer_phone}`">{{ order.customer_phone }}</a></p><p class="muted">{{ date(order.created_at) }}<br />{{ order.delivery_method === 'shipping' ? '宅配' : '自取' }} · {{ order.items?.length || 0 }} 項商品</p><div class="order-heading"><strong>{{ money(order.total) }}</strong><button class="button ghost" @click="open(order)">查看訂單</button></div></article></div>
  <div v-if="selected" class="modal" role="dialog" aria-modal="true" aria-labelledby="order-dialog-title"><form class="dialog form" @submit.prevent="save"><div class="workhead"><div><p class="eyebrow">訂單明細</p><h2 id="order-dialog-title">{{ selected.order_number }}</h2></div><button type="button" class="button ghost" :disabled="busy" @click="selected = null">關閉</button></div><p>{{ date(selected.created_at) }} · {{ statuses[selected.status] }}</p><section class="order-contact"><h3>顧客與配送</h3><p>{{ selected.customer_name }}<br /><a :href="`tel:${selected.customer_phone}`">{{ selected.customer_phone }}</a></p><p>{{ selected.delivery_method === 'shipping' ? '宅配寄送' : '自行取貨' }}</p><p v-if="selected.delivery_method === 'shipping'" class="order-address">{{ selected.address }}</p></section><section><h3>訂購商品</h3><article v-for="(item,index) in selected.items" :key="index" class="order-item"><div><strong>{{ item.title }}</strong><p class="muted">{{ item.options?.join('／') || item.sku }} · 數量 {{ item.quantity }}<br />單價 {{ money(item.unit_price) }}</p></div><strong>{{ money(item.total) }}</strong></article></section><dl class="order-amounts"><div><dt>商品小計</dt><dd>{{ money(selected.subtotal) }}</dd></div><div><dt>運費</dt><dd>{{ money(selected.shipping_fee) }}</dd></div><div><dt>訂單總額</dt><dd><strong>{{ money(selected.total) }}</strong></dd></div></dl><template v-if="can('orders.update.all')"><label class="field">處理狀態<select v-model="status" :disabled="busy"><option v-for="option in availableStatuses" :key="option.key" :value="option.key">{{ option.label }}</option></select></label><label class="field">內部處理備註<textarea v-model="staffNote" :disabled="busy" maxlength="2000" rows="3" /></label><p v-if="error" class="error" role="alert">{{ error }}</p><p v-if="saved" class="notice" role="status">{{ saved }}</p><button class="button" :disabled="busy">{{ busy ? '儲存中…' : '儲存訂單' }}</button></template><p v-else-if="selected.staff_note" class="notice">處理備註：{{ selected.staff_note }}</p></form></div>
</template>
<style scoped>
.order-filters{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;margin-bottom:20px}.order-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}.order-card{min-width:0;overflow-wrap:anywhere}.notification-focus{border-color:#c69d4d;background:#fff8e8;box-shadow:0 0 0 3px rgba(198,157,77,.25)}.order-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.order-contact{padding:16px;background:#f3f6f0;border-radius:12px}.order-contact h3{margin-top:0}.order-address{white-space:pre-wrap;overflow-wrap:anywhere}.order-item{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid var(--line)}.order-item p{margin:6px 0}.order-amounts div{display:flex;justify-content:space-between;margin:12px 0}.order-amounts dd{margin:0}.dialog{max-width:720px}@media(max-width:600px){.order-filters{grid-template-columns:1fr;gap:0}.order-list{grid-template-columns:1fr}.order-card{padding:20px}}
</style>
