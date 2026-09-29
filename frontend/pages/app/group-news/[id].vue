<script setup lang="ts">
definePageMeta({ layout: "app" });
const route = useRoute();
const item = ref<any>(null),
  loading = ref(true),
  error = ref("");
const taipei = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(value))
    : "";
onMounted(async () => {
  try {
    item.value = await api<any>(`/group-news/${route.params.id}`);
  } catch (e: any) {
    error.value = e.message || "找不到這則小組消息。";
  } finally {
    loading.value = false;
  }
});
</script>
<template>
  <section class="section">
    <div class="container">
      <NuxtLink to="/app/group-news">← 小組消息</NuxtLink>
      <p v-if="loading" class="empty">載入中…</p>
      <p v-else-if="error" class="notice">{{ error }}</p>
      <article v-else-if="item" class="article">
        <p class="eyebrow">{{ item.group_names?.join("、") }}</p>
        <h1>{{ item.title }}</h1>
        <p class="muted">
          {{ taipei(item.published_at) }} · {{ item.author_name || "協會編輯" }}
        </p>
        <p v-if="item.summary">{{ item.summary }}</p>
        <RichArticle :html="item.body_html || item.body || ''" />
      </article>
    </div>
  </section>
</template>
