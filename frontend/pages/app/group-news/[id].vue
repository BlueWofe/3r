<script setup lang="ts">
definePageMeta({ layout: "app" });
const route = useRoute();
const { data, error } = await useAsyncData(
  () => `group-${route.params.id}`,
  () => api<any>(`/group-news/${route.params.id}`),
);
</script>
<template>
  <section class="section">
    <article class="container" style="max-width: 780px">
      <NuxtLink to="/app/group-news">← 小組消息</NuxtLink>
      <p v-if="error" class="notice">此消息目前無法查看。</p>
      <template v-else
        ><p class="eyebrow">{{ data?.data?.group_names?.join("、") }}</p>
        <h1>{{ data?.data?.title }}</h1>
        <RichArticle
          :html="data?.data?.body_html"
          :text="data?.data?.body || data?.data?.summary"
      /></template>
    </article>
  </section>
</template>
