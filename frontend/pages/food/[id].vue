<script setup lang="ts">
const route = useRoute();
const cart = useShoppingCart();
const added = ref(false);
const canAdd = computed(
  () =>
    selected.value?.active &&
    Number.isInteger(quantity.value) &&
    quantity.value > 0 &&
    quantity.value <= selected.value.stock,
);
function addToCart() {
  if (canAdd.value) {
    cart.add(Number(route.params.id), selected.value.id, quantity.value);
    added.value = true;
  }
}
const { data } = await useAsyncData(
  () => `product-${route.params.id}`,
  () => api<any>(`/public/products/${route.params.id}`),
);
const selected = ref<any>(null),
  quantity = ref(1),
  quote = ref<any>(null),
  quoteError = ref(""),
  quoting = ref(false);
let requestId = 0;
const variants = computed(() => data.value?.data?.metadata?.variants || []);
onMounted(() => {
  selected.value =
    variants.value.find((v: any) => v.active && v.stock > 0) ||
    variants.value.find((v: any) => v.active) ||
    null;
});
async function getQuote() {
  if (!selected.value || quoting.value) return;
  const id = ++requestId;
  quoting.value = true;
  quote.value = null;
  quoteError.value = "";
  try {
    const result = await api(
      `/public/products/${route.params.id}/quote?variant_id=${encodeURIComponent(selected.value.id)}&quantity=${quantity.value}`,
    );
    if (id === requestId) quote.value = result;
  } catch (e: any) {
    if (id === requestId) quoteError.value = e.message;
  } finally {
    if (id === requestId) quoting.value = false;
  }
}
watch([selected, quantity], () => {
  added.value = false;
  requestId++;
  quoting.value = false;
  quote.value = null;
  quoteError.value = "";
});
</script>
<template>
  <PublicHero
    eyebrow="WITH LOVE MADE"
    :title="data?.data?.title || '愛心好食'"
    :description="data?.data?.summary"
    photo
    ><NuxtLink class="button support-primary" to="/food"
      >返回愛心好食 →</NuxtLink
    ></PublicHero
  >
  <section class="section">
    <div class="container">
      <NuxtLink class="back-link" to="/food">← 回到愛心好食</NuxtLink>
    </div>
    <div class="container grid responsive-two product-layout">
      <ProductGallery
        :key="String(route.params.id)"
        :title="data?.data?.title || '商品'"
        :main-id="data?.data?.image_id"
        :gallery-ids="data?.data?.metadata?.gallery_ids || []"
        :slug="data?.data?.slug"
      />
      <article>
        <p class="eyebrow">愛心好食</p>
        <p class="product-title serif">{{ data?.data?.title }}</p>
        <p class="muted">{{ data?.data?.body || data?.data?.summary }}</p>
        <p class="muted spec-list">
          成分：{{ data?.data?.metadata?.ingredients || "未提供"
          }}<br />過敏原：{{ data?.data?.metadata?.allergens || "未提供"
          }}<br />淨重：{{ data?.data?.metadata?.net_weight || "未提供"
          }}<br />保存期限：{{ data?.data?.metadata?.shelf_life || "未提供"
          }}<br />保存方式：{{ data?.data?.metadata?.storage || "未提供"
          }}<br />產地：{{ data?.data?.metadata?.origin || "未提供"
          }}<br />展示單位：{{ data?.data?.metadata?.unit || "未提供" }}
        </p>
        <div v-if="variants.length" class="card">
          <h3>選擇規格與數量</h3>
          <label class="field"
            >規格<select v-model="selected">
              <option
                v-for="v in variants.filter((x: any) => x.active)"
                :key="v.id"
                :value="v"
              >
                {{ v.options?.join("／") || "一般規格" }} · 基本價
                {{ v.price }} TWD · 庫存 {{ v.stock }}
              </option>
            </select></label
          ><label class="field"
            >數量<input
              v-model.number="quantity"
              type="number"
              min="1"
              :max="selected?.stock || 1"
          /></label>
          <p v-if="selected" class="muted">
            基本單價：{{ selected.price }} TWD
          </p>
          <ul v-if="selected?.wholesale?.length" class="muted">
            <li v-for="tier in selected.wholesale" :key="tier.min_quantity">
              滿 {{ tier.min_quantity }} 件，優惠單價 {{ tier.unit_price }} TWD
            </li>
          </ul>
          <button class="button" :disabled="quoting" @click="getQuote">
            {{ quoting ? "計算中…" : "查詢報價" }}
          </button>
          <p v-if="quote" class="notice">
            單價 {{ quote.unit_price }} {{ quote.currency }} · 合計
            {{ quote.total }} {{ quote.currency
            }}<span v-if="quote.applied_min_quantity"
              >（已套用 {{ quote.applied_min_quantity }} 件大量優惠）</span
            >
          </p>
          <p v-if="quoteError" class="error">{{ quoteError }}</p>
          <div class="actions product-cart-actions">
            <button class="button" :disabled="!canAdd" @click="addToCart">
              加入購物車</button
            ><NuxtLink class="button ghost" to="/cart">前往購物車</NuxtLink>
          </div>
          <p v-if="added" class="notice" role="status">
            已加入購物車，可繼續選購或前往確認訂單。
          </p>
        </div>
        <div class="notice">
          免登入即可送出訂單，由協會聯絡確認。本網站不提供線上付款。
        </div>
      </article>
    </div>
  </section>
  <PublicSupport />
</template>

<style scoped>
.product-layout.grid {
  grid-template-columns: minmax(0, 14fr) minmax(0, 15fr);
  gap: 40px;
}
@media (max-width: 760px) {
  .product-layout.grid { gap: 24px; }
}
.product-cart-actions {
  margin-top: 18px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.product-layout article {
  min-width: 0;
}
.product-layout .card {
  margin: 20px 0;
}
</style>
