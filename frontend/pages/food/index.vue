<script setup lang="ts">
const route = useRoute();
const router = useRouter();
const selectedCategory = computed({
  get: () => String(route.query.category || ""),
  set: (category: string) => { void router.replace({ query: { ...route.query, category: category || undefined } }); },
});
const { data, pending, error, refresh } = await useAsyncData("products", () =>
  api<any>(
    `/public/products?category=${encodeURIComponent(selectedCategory.value)}&limit=100`,
  ),
);
const categories = computed(() => data.value?.categories || []);
const products = computed(() => data.value?.data || []);
watch(selectedCategory, () => refresh());
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">WITH LOVE MADE</div>
      <h1>愛心好食</h1>
    </div>
  </div>
  <section class="section">
    <div class="container">
      <p class="notice">本頁為服務成果展示，並不提供線上購買或付款。</p>
      <section class="bulk-cta">
        <h2>企業 CSR 與教會節慶禮盒大宗認購專案</h2>
        <p>支援客製祝福燙金小卡、開立合法三聯式統一發票及捐贈抵扣憑證</p>
        <div class="actions">
          <NuxtLink class="button" to="/contact?category=大宗認購專案"
            >登記大宗洽詢</NuxtLink
          ><NuxtLink class="button ghost" to="/contact?category=試吃"
            >登記試吃</NuxtLink
          >
        </div>
      </section>
      <label class="field product-filter"
        >產品分類<select v-model="selectedCategory">
          <option value="">全部產品</option>
          <option
            v-for="category in categories"
            :key="category"
            :value="category"
          >
            {{ category }}
          </option>
        </select></label
      >
      <p v-if="pending" class="empty">載入產品中…</p>
      <p v-else-if="error" class="notice">產品暫時無法載入，請稍後再試。</p>
      <p v-else-if="!products.length" class="empty">此分類目前沒有公開產品。</p>
      <div v-else class="grid cards" style="margin-top: 24px">
        <NuxtLink
          v-for="p in products"
          :key="p.id"
          class="card media-card"
          :to="`/food/${p.id}`"
          ><img
            v-if="p.image_id"
            class="cover-thumb"
            :src="`/api/v1/files/${p.image_id}/download`"
            :alt="p.title"
          />
          <div v-else class="media-placeholder" aria-hidden="true">🍞</div>
          <span v-if="p.category" class="eyebrow">{{ p.category }}</span>
          <h3>{{ p.title }}</h3>
          <p class="muted">{{ p.summary }}</p>
          <small>認識這份心意 →</small></NuxtLink
        >
      </div>
    </div>
  </section>
</template>
<style scoped>
.bulk-cta {
  margin: 18px 0;
  padding: clamp(20px, 4vw, 38px);
  background: var(--pine);
  color: #fff;
  border-radius: 16px;
}
.bulk-cta h2 {
  margin: 0;
  font-size: clamp(22px, 3vw, 34px);
}
.bulk-cta p {
  max-width: 720px;
}
.bulk-cta .ghost {
  color: #fff;
  border-color: #fff;
}
.product-filter {
  max-width: 280px;
  margin-top: 22px;
}
</style>
