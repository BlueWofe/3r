<script setup lang="ts">
const { items, ready, remove, clear } = useShoppingCart();
const settings = ref<any>(null), settingsError = ref(''), quote = ref<any>(null), error = ref(''), busy = ref(false), quoting = ref(false);
const method = ref('shipping');
const customer = reactive({ customer_name: '', customer_phone: '', address: '' });
const success = ref<any>(null), pendingRequest = ref<any>(null);
let requestId = 0;
const money = (value: number) => new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(value);
const lineQuote = (item: { product_id: number; variant_id: string }) => quote.value?.items?.find((line: any) => line.product_id === item.product_id && line.variant_id === item.variant_id);
async function loadSettings() {
  settingsError.value = '';
  try { settings.value = await api<any>('/public/shipping-settings'); if (!settings.value.shipping_enabled && settings.value.pickup_enabled) method.value = 'pickup'; }
  catch (e: any) { settingsError.value = e.message; }
}
async function recalculate() {
  const id = ++requestId;
  quote.value = null; error.value = '';
  quoting.value = false;
  if (!ready.value || !items.value.length || !settings.value) return;
  quoting.value = true;
  try {
    const result = await api<any>('/public/orders/quote', { method: 'POST', body: { delivery_method: method.value, items: items.value.map(x => ({ ...x })) } });
    if (id === requestId) quote.value = result;
  } catch (e: any) { if (id === requestId) error.value = e.message; }
  finally { if (id === requestId) quoting.value = false; }
}
watch([items, method, ready, settings], recalculate, { deep: true });
onMounted(loadSettings);
async function submit() {
  if (busy.value || (!pendingRequest.value && !quote.value)) return;
  if (!pendingRequest.value) pendingRequest.value = { ...customer, address: method.value === 'shipping' ? customer.address : '', delivery_method: method.value, items: items.value.map(x => ({ ...x })), expected_total_cents: quote.value.total_cents, idempotency_key: newId() };
  busy.value = true; error.value = '';
  try {
    success.value = await api<any>('/public/orders', { method: 'POST', body: pendingRequest.value });
    pendingRequest.value = null; clear(); Object.assign(customer, { customer_name: '', customer_phone: '', address: '' });
  } catch (e: any) {
    if ([409, 422, 403].includes(e.code)) { pendingRequest.value = null; await recalculate(); }
    error.value = e.message + (pendingRequest.value ? ' 若尚未收到確認，請按「重試送出」；系統會使用同一筆訂單識別碼避免重複建立。' : ' 請確認商品、金額與資料後再次送出。');
  } finally { busy.value = false; }
}
</script>
<template>
  <div class="pagehead"><div class="container"><p class="eyebrow">WITH LOVE MADE</p><h1>購物車</h1><p class="muted">確認商品與配送方式，留下聯絡資料即可送出訂單。無需登入，也不提供線上付款。</p></div></div>
  <section class="section"><div class="container">
    <article v-if="success" class="card order-success" role="status"><span class="eyebrow">ORDER RECEIVED</span><h2>已收到您的訂單</h2><p>訂單編號：<strong>{{ success.order_number }}</strong></p><p>訂單金額：{{ money(success.total) }}</p><p class="muted">請保留編號，協會將依您提供的聯絡資訊確認後續事宜。此頁未收取付款。</p><NuxtLink class="button" to="/food">繼續瀏覽商品</NuxtLink></article>
    <template v-else>
      <NuxtLink class="back-link" to="/food">← 繼續選購愛心好食</NuxtLink>
      <p v-if="!ready" class="empty">載入購物車中…</p>
      <div v-else-if="!items.length" class="card empty"><h2>購物車還沒有商品</h2><p>選擇喜歡的規格與數量，將心意加入購物車。</p><NuxtLink class="button" to="/food">瀏覽愛心好食</NuxtLink></div>
      <form v-else class="checkout-grid" @submit.prevent="submit">
        <section class="card cart-products"><h2>您的商品</h2>
          <fieldset :disabled="busy || !!pendingRequest" class="cart-controls">
            <article v-for="(item, index) in items" :key="`${item.product_id}-${item.variant_id}`" class="cart-row">
              <div><NuxtLink :to="`/food/${item.product_id}`"><strong>{{ lineQuote(item)?.title || `商品 #${item.product_id}` }}</strong></NuxtLink><p class="muted">{{ lineQuote(item)?.options?.join('／') || `規格 ${item.variant_id}` }}</p><small v-if="lineQuote(item)">單價 {{ money(lineQuote(item).unit_price) }}<span v-if="lineQuote(item).applied_min_quantity"> · 已套用大量優惠</span></small></div>
              <div class="cart-quantity"><label class="field">數量<input v-model.number="item.quantity" type="number" min="1" max="100000" step="1" required :aria-label="`商品 ${index + 1} 數量`" /></label><button type="button" class="button ghost" @click="remove(index)">移除</button></div>
              <strong v-if="lineQuote(item)">{{ money(lineQuote(item).total) }}</strong>
            </article>
          </fieldset>
          <p v-if="quoting" class="muted" aria-live="polite">正在計算最新商品價格與運費…</p>
          <button v-if="!quote && !quoting && !pendingRequest" type="button" class="button ghost" @click="recalculate">重新計算金額</button>
        </section>
        <section class="card checkout-details"><h2>配送與聯絡資料</h2>
          <p v-if="settingsError" class="error">{{ settingsError }} <button type="button" class="button ghost" @click="loadSettings">重試載入配送設定</button></p>
          <fieldset :disabled="busy || !!pendingRequest" class="cart-controls">
            <label class="field">配送方式<select v-model="method" required><option v-if="settings?.shipping_enabled" value="shipping">宅配寄送</option><option v-if="settings?.pickup_enabled" value="pickup">自行取貨</option></select></label>
            <p v-if="settings && !settings.shipping_enabled && !settings.pickup_enabled" class="notice">目前暫停受理訂單。</p>
            <p v-if="method === 'pickup' && settings?.pickup_instructions" class="notice">{{ settings.pickup_instructions }}</p>
            <p v-if="method === 'shipping' && settings" class="muted">運費 {{ money(settings.flat_fee) }}<span v-if="settings.free_shipping_threshold !== null">，商品滿 {{ money(settings.free_shipping_threshold) }} 免運</span>。</p>
            <label class="field">收件／聯絡人姓名<input v-model.trim="customer.customer_name" autocomplete="name" maxlength="100" required /></label>
            <label class="field">聯絡電話<input v-model.trim="customer.customer_phone" type="tel" autocomplete="tel" maxlength="30" required /></label>
            <label v-if="method === 'shipping'" class="field">收件地址<textarea v-model.trim="customer.address" autocomplete="street-address" maxlength="500" required rows="3" /></label>
          </fieldset>
          <dl v-if="quote" class="checkout-total"><div><dt>商品小計</dt><dd>{{ money(quote.subtotal) }}</dd></div><div><dt>運費</dt><dd>{{ money(quote.shipping_fee) }}</dd></div><div class="grand-total"><dt>訂單總額</dt><dd>{{ money(quote.total) }}</dd></div></dl>
          <p class="muted">送出後將建立訂單，由協會聯絡確認；此流程不收取線上付款。</p>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <button class="button checkout-submit" :disabled="busy || (!pendingRequest && (!quote || quoting))">{{ busy ? '送出中…' : pendingRequest ? '重試送出' : '確認並送出訂單' }}</button>
        </section>
      </form>
    </template>
  </div></section>
</template>
<style scoped>
.checkout-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,1fr);gap:24px;margin-top:24px;align-items:start}.cart-controls{border:0;padding:0;margin:0;min-width:0}.cart-row{display:grid;grid-template-columns:minmax(0,1fr) 110px;gap:16px;padding:22px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.cart-row p{margin:6px 0}.cart-quantity .button{width:100%;padding:8px}.cart-quantity input{width:100%}.checkout-total{padding-top:16px;border-top:1px solid var(--line)}.checkout-total div{display:flex;justify-content:space-between;gap:16px;margin:12px 0}.checkout-total dd{margin:0}.grand-total{font-size:22px;font-weight:700;color:var(--pine)}.checkout-submit{width:100%;min-height:48px}.order-success{max-width:650px;margin:auto;background:#f1f6ee}.checkout-details h2,.cart-products h2{margin-top:0}@media(max-width:760px){.checkout-grid{grid-template-columns:1fr}.cart-row{grid-template-columns:minmax(0,1fr) 96px}.checkout-details,.cart-products{padding:20px}}
</style>
