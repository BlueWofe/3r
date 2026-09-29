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
          class="card"
          :to="`/food/${p.id}`"
          ><div
            style="
              height: 150px;
              background: #eee3cb;
              border-radius: 5px;
              display: grid;
              place-items: center;
              font-size: 44px;
            "
          >
            🍞
          </div>
          <h3>{{ p.title }}</h3>
          <p class="muted">{{ p.summary }}</p>
          <small>認識這份心意 →</small></NuxtLink
        >
      </div>
    </div>
  </section>
</template>
