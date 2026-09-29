<script setup lang="ts">
const route = useRoute();
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
    variants.value.find((v: any) => v.active) || variants.value[0] || null;
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
  requestId++;
  quoting.value = false;
  quote.value = null;
  quoteError.value = "";
});
</script>
<template>
  <section class="section">
    <div
      class="container grid responsive-two"
      style="grid-template-columns: 1fr 1fr"
    >
      <div
        v-if="data?.data?.image_id"
        ><img
          class="product-detail-image"
          :src="`/api/v1/files/${data.data.image_id}/download`"
          :alt="data.data.title"
      /></div>
      <div
        v-else
        style="
          min-height: 350px;
          background: #eee3cb;
          border-radius: 8px;
          display: grid;
          place-items: center;
          font-size: 100px;
        "
      >
        🍞
      </div>
      <article>
        <p class="eyebrow">服務成果展示</p>
        <h1 class="serif">{{ data?.data?.title }}</h1>
        <p class="muted">{{ data?.data?.body || data?.data?.summary }}</p>
        <div
          v-if="data?.data?.metadata?.gallery_ids?.length"
          class="grid"
          style="grid-template-columns: repeat(3, 1fr)"
        >
          <div
            v-for="id in data.data.metadata.gallery_ids"
            :key="id"
            ><img
              :src="`/api/v1/files/${id}/download`"
              :alt="data.data.title"
              class="product-gallery-image"
          /></div>
        </div>
        <p class="muted">
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
        </div>
        <div class="notice">此為展示／詢問模式，沒有購物車、訂單或結帳。</div>
      </article>
    </div>
  </section>
</template>
