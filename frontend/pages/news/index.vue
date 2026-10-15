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
  <section class="section public-split-intro">
    <div class="container public-split-grid">
      <div>
        <p class="eyebrow">RENEWAL / RESTORATION / HOPE</p>
        <h1>每一次同行，都有故事</h1>
        <p class="muted">
          從高牆內到社區，我們將陪伴、信仰與關係串成一條完整的路。這裡收錄協會最新消息、事工分享與生命見證。
        </p>
        <a class="button" href="#news-list">閱讀最新消息 →</a>
      </div>
      <img src="/images/redesign/news-hero.png" alt="陪伴與同行的情境示意" />
    </div>
  </section>
  <section id="news-list" class="section news-index">
    <div class="container">
      <div class="news-filter-bar">
        <div class="tabs" aria-label="文章類型">
          <button
            v-for="item in [
              { value: '', label: '全部' },
              { value: 'news', label: '最新消息' },
              { value: 'sharing', label: '事工分享' },
              { value: 'testimony', label: '生命見證' },
            ]"
            :key="item.value"
            :class="{ selected: articleType === item.value }"
            :aria-pressed="articleType === item.value"
            @click="
              articleType = item.value;
              refresh();
            "
          >
            {{ item.label }}
          </button>
        </div>
        <label class="field"
          >主題分類<select v-model="category" @change="() => refresh()">
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
      <div v-if="pending" class="empty">載入中…</div>
      <div v-else-if="error" class="notice">目前無法取得消息，請稍後再試。</div>
      <div v-else-if="!data?.data?.length" class="card empty">
        目前沒有符合篩選條件的文章。
      </div>
      <div v-else class="grid cards">
        <NuxtLink
          v-for="n in data?.data"
          :key="n.id"
          class="card news-row"
          :to="`/news/${n.id}`"
          ><img
            v-if="n.image_id"
            class="cover-thumb"
            :src="`/api/v1/files/${n.image_id}/download`"
            :alt="n.title"
          /><span v-else class="news-row-placeholder" aria-hidden="true"
            ><MinistryIllustration kind="growth"
          /></span>
          <div class="news-row-content">
            <span class="eyebrow"
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
            <small>閱讀故事 →</small>
          </div></NuxtLink
        >
      </div>
    </div>
  </section>
  <PublicSupport />
</template>
