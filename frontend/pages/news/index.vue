<script setup lang="ts">
const { data, pending, error } = await useAsyncData("news", () =>
  api<any>("/public/news"),
);
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">NEWS</div>
      <h1>最新消息</h1>
    </div>
  </div>
  <section class="section">
    <div class="container">
      <p class="demo">示範內容</p>
      <div v-if="pending" class="empty">載入中…</div>
      <div v-else-if="error" class="notice">目前無法取得消息，請稍後再試。</div>
      <div v-else class="grid cards">
        <NuxtLink
          v-for="n in data?.data"
          :key="n.id"
          class="card"
          :to="`/news/${n.id}`"
          ><img
            v-if="n.image_id"
            :src="`/api/v1/files/${n.image_id}/download`"
            :alt="n.title"
            style="
              height: 150px;
              width: 100%;
              object-fit: cover;
              border-radius: 5px;
              margin-bottom: 14px;
            "
          /><span class="eyebrow">{{ n.category || "協會消息" }}</span>
          <h3>{{ n.title }}</h3>
          <p class="muted">{{ n.summary }}</p>
          <small>閱讀故事 →</small></NuxtLink
        >
      </div>
    </div>
  </section>
</template>
