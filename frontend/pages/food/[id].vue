<script setup lang="ts">
const route = useRoute();
const { data } = await useAsyncData(
  () => `product-${route.params.id}`,
  () => api<any>(`/public/products/${route.params.id}`),
);
</script>
<template>
  <section class="section">
    <div class="container grid" style="grid-template-columns: 1fr 1fr">
      <img
        v-if="data?.data?.image_id"
        :src="`/api/v1/files/${data.data.image_id}/download`"
        :alt="data.data.title"
        style="
          min-height: 350px;
          width: 100%;
          height: 100%;
          object-fit: cover;
          border-radius: 8px;
        "
      />
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
        <div class="notice">此為示範展示，沒有購物車、價格或銷售功能。</div>
      </article>
    </div>
  </section>
</template>
