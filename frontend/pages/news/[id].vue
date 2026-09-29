<script setup lang="ts">
const route = useRoute();
const { data, error } = await useAsyncData(
  () => `news-${route.params.id}`,
  () => api<any>(`/public/news/${route.params.id}`),
);
const taipeiDateTime = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(value))
    : "發布時間未提供";
</script>
<template>
  <section class="section">
    <article class="container" style="max-width: 780px">
      <NuxtLink class="muted" to="/news">← 回到消息列表</NuxtLink>
      <p class="eyebrow" style="margin-top: 30px">
        {{ data?.data?.category || "最新消息" }}
      </p>
      <a
        v-if="data?.data?.image_id"
        :href="`/api/v1/files/${data.data.image_id}/download`"
        target="_blank"
        rel="noopener"
        aria-label="檢視完整文章圖片"
        ><img
          class="article-cover-image"
          :src="`/api/v1/files/${data.data.image_id}/download`"
          :alt="data.data.title"
      /></a>
      <h1 class="serif" style="font-size: 42px">
        {{ data?.data?.title || "消息內容" }}
      </h1>
      <p class="muted">
        {{ taipeiDateTime(data?.data?.published_at) }} ·
        {{ data?.data?.author_name || "協會編輯" }}
      </p>
      <p v-if="error" class="notice">無法取得此篇內容。</p>
      <RichArticle
        :html="data?.data?.body_html"
        :text="data?.data?.body || data?.data?.summary"
      />
    </article>
  </section>
</template>
