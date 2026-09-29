<script setup lang="ts">
const { data } = await useAsyncData("products", () =>
  api<any>("/public/products"),
);
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
      <div class="grid cards" style="margin-top: 24px">
        <NuxtLink
          v-for="p in data?.data"
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
          <h3>{{ p.title }}</h3>
          <p class="muted">{{ p.summary }}</p>
          <small>認識這份心意 →</small></NuxtLink
        >
      </div>
    </div>
  </section>
</template>
