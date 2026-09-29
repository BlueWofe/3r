<script setup lang="ts">
const route = useRoute();
const { data, error } = await useAsyncData(
  () => `news-${route.params.id}`,
  () => api<any>(`/public/news/${route.params.id}`),
);
</script>
<template>
  <section class="section">
    <article class="container" style="max-width: 780px">
      <NuxtLink class="muted" to="/news">← 回到消息列表</NuxtLink>
      <p class="eyebrow" style="margin-top: 30px">
        {{ data?.data?.category || "示範消息" }}
      </p>
      <h1 class="serif" style="font-size: 42px">
        {{ data?.data?.title || "消息內容" }}
      </h1>
      <p v-if="error" class="notice">無法取得此篇內容。</p>
      <p class="muted" style="white-space: pre-wrap">
        {{ data?.data?.body || data?.data?.summary }}
      </p>
    </article>
  </section>
</template>
