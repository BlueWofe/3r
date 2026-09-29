<script setup lang="ts">
const articleType = ref(""),
  category = ref("");
const { data, pending, error, refresh } = await useAsyncData("news", () =>
  api<any>(
    `/public/news?article_type=${articleType.value}&category=${encodeURIComponent(category.value)}`,
  ),
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
  <div class="pagehead">
    <div class="container">
      <div class="toolbar">
        <label
          >文章類型<select
            v-model="articleType"
            aria-label="文章類型"
            @change="() => refresh()"
          >
            <option value="">全部文章</option>
            <option value="news">最新消息</option>
            <option value="sharing">事工分享</option>
            <option value="testimony">生命見證</option>
          </select></label
        ><label
          >主題分類<select
            v-model="category"
            aria-label="主題分類"
            @change="() => refresh()"
          >
            <option value="">全部主題</option>
            <option
              v-for="item in [
                '監獄事工',
                '更生輔導',
                '志工招募',
                '愛心義賣',
                '代禱消息',
                '協會公告',
              ]"
              :key="item"
            >
              {{ item }}
            </option>
          </select></label
        >
      </div>
      <div class="eyebrow">NEWS</div>
      <h1>最新消息</h1>
    </div>
  </div>
  <section class="section">
    <div class="container">
      <p class="demo">示範內容</p>
      <div v-if="pending" class="empty">載入中…</div>
      <div v-else-if="error" class="notice">目前無法取得消息，請稍後再試。</div>
      <div v-else-if="!data?.data?.length" class="card empty">
        目前沒有符合篩選條件的文章。
      </div>
      <div v-else class="grid cards">
        <NuxtLink
          v-for="n in data?.data"
          :key="n.id"
          class="card media-card"
          :to="`/news/${n.id}`"
          ><img
            v-if="n.image_id"
            class="cover-thumb"
            :src="`/api/v1/files/${n.image_id}/download`"
            :alt="n.title"
          /><span v-else class="media-placeholder" aria-hidden="true">✦</span
          ><span class="eyebrow"
            >{{
              n.article_type === "sharing"
                ? "事工分享"
                : n.article_type === "testimony" || n.category === "見證分享"
                  ? "生命見證"
                  : "最新消息"
            }}
            · {{ n.category || "未分類" }}</span
          >
          <h3>{{ n.title }}</h3>
          <p class="muted">{{ n.summary }}</p>
          <small class="muted"
            >{{ taipeiDateTime(n.published_at) }} ·
            {{ n.author_name || "協會編輯" }}</small
          >
          <small>閱讀故事 →</small></NuxtLink
        >
      </div>
    </div>
  </section>
</template>
