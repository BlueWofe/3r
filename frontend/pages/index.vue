<script setup lang="ts">
const {
  data: latestNews,
  pending: newsPending,
  error: newsError,
} = await useAsyncData("homepage-latest-news", () =>
  api<any>("/public/news?limit=3"),
);
const taipeiDate = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "long",
        day: "numeric",
      }).format(new Date(value))
    : "發布日期未提供";
</script>
<template>
  <section class="hero">
    <div class="container">
      <div class="eyebrow">RENEWAL • RESTORATION • HOPE</div>
      <h1>讓每一段生命，<br />都能重新被看見。</h1>
      <p class="lead">
        我們走進高牆，也陪伴走出高牆的人與家庭，以信仰、關係和實際支持，見證更新的可能。
      </p>
      <div class="actions">
        <NuxtLink class="button gold" to="/about">認識我們</NuxtLink
        ><NuxtLink class="button ghost" to="/donate">支持陪伴</NuxtLink>
      </div>
    </div>
  </section>
  <section class="section">
    <div class="container">
      <div class="eyebrow">OUR MINISTRY</div>
      <h2>陪伴旅程</h2>
      <ol class="ministry-grid">
        <li>
          <NuxtLink
            to="/about"
            class="ministry-panel ministry-panel--door"
            aria-label="走進高牆｜認識協會"
          >
            <span class="ministry-number">01 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="door" class="ministry-illustration" />
            <div class="ministry-caption">
              <h3>走進高牆<span class="ministry-action">｜認識協會</span></h3>
              <p>讓關懷，走進每一個角落。</p>
            </div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink
            to="/contact"
            class="ministry-panel ministry-panel--people"
            aria-label="建立信任｜聯絡我們"
          >
            <span class="ministry-number">02 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="people" class="ministry-illustration" />
            <div class="ministry-caption">
              <h3>建立信任<span class="ministry-action">｜聯絡我們</span></h3>
              <p>用傾聽，陪伴生命的改變。</p>
            </div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink
            to="/news"
            class="ministry-panel ministry-panel--growth"
            aria-label="預備復歸｜最新消息"
          >
            <span class="ministry-number">03 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="growth" class="ministry-illustration" />
            <div class="ministry-caption">
              <h3>預備復歸<span class="ministry-action">｜最新消息</span></h3>
              <p>為重新出發，預備一份力量。</p>
            </div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink
            to="/donate"
            class="ministry-panel ministry-panel--home"
            aria-label="社區同行｜支持事工"
          >
            <span class="ministry-number">04 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="home" class="ministry-illustration" />
            <div class="ministry-caption">
              <h3>社區同行<span class="ministry-action">｜支持事工</span></h3>
              <p>回家的路，我們一起走。</p>
            </div>
          </NuxtLink>
        </li>
      </ol>
    </div>
  </section>
  <section class="section tint">
    <div
      class="container grid responsive-two"
      style="grid-template-columns: 1fr 1fr; align-items: center"
    >
      <div>
        <div class="eyebrow">A STORY OF HOPE</div>
        <h2>更新的足跡</h2>
        <p class="muted">記錄事工近況，分享陪伴與生命更新的故事。</p>
        <NuxtLink class="button" to="/news">閱讀更多消息與見證</NuxtLink>
      </div>
      <div v-if="newsPending" class="empty">載入最新消息中…</div>
      <p v-else-if="newsError" class="notice">
        目前無法取得最新消息，請稍後再試。
      </p>
      <p v-else-if="!latestNews?.data?.length" class="empty">
        目前尚無已發布的消息。
      </p>
      <ol v-else class="story-timeline">
        <li v-for="article in latestNews.data" :key="article.id">
          <NuxtLink :to="`/news/${article.id}`">
            <span class="eyebrow">{{ article.category || "最新消息" }}</span>
            <b>{{ article.title }}</b
            ><br />
            <small class="muted">{{ taipeiDate(article.published_at) }}</small>
          </NuxtLink>
        </li>
      </ol>
    </div>
  </section>
</template>
