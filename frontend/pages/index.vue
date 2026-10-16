<script setup lang="ts">
const {
  data: latestNews,
  pending: newsPending,
  error: newsError,
} = await useAsyncData("homepage-latest-news", () =>
  api<any>("/public/news?limit=3"),
);
const { data: association } = await useAsyncData("homepage-association", () =>
  api<any>("/public/pages/about").catch(() => ({ data: null })),
);
const imageFor = (id?: number) =>
  Number.isInteger(id) && Number(id) > 0
    ? `/api/v1/files/${id}/download`
    : null;
const taipeiDate = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).format(new Date(value))
    : "發布日期未提供";
const ministries = [
  {
    title: "走進高牆",
    action: "認識協會",
    body: "主動走向需要陪伴的生命。",
    icon: "Home",
    to: "/about",
  },
  {
    title: "建立信任",
    action: "聯絡我們",
    body: "每一次傾聽，都有意義。",
    icon: "Heart",
    to: "/contact",
  },
  {
    title: "預備復歸",
    action: "最新消息",
    body: "為下一步，預備一份力量。",
    icon: "Star",
    to: "/news",
  },
  {
    title: "社區同行",
    action: "支持事工",
    body: "回家的路，一起走。",
    icon: "Users",
    to: "/donate",
  },
];
</script>
<template>
  <section class="renewal-hero">
    <div class="container renewal-hero-inner">
      <div class="renewal-hero-copy">
        <p class="eyebrow">RENEWAL / RESTORATION / HOPE</p>
        <h1>讓每一段生命，<br />都能重新被看見。</h1>
        <p class="lead">
          我們走進高牆，也陪伴走出高牆的人與家庭。<br />從信仰與關係開始，讓更新成為可能。
        </p>
        <div class="actions">
          <NuxtLink class="button support-primary" to="/about"
            >認識我們<span aria-hidden="true"> →</span></NuxtLink
          ><a class="button support-secondary" href="#ministry">了解事工</a>
        </div>
      </div>
      <img
        class="renewal-hero-mobile"
        src="/images/redesign/imgHeroSection.png"
        alt="陽光灑落的林間小徑，象徵重新出發"
      />
    </div>
  </section>
  <div class="brand-strip">
    <div class="container">
      <div class="brand-values">
        <span>復甦</span><span>更新</span><span>發展</span>
      </div>
      <p>中華復甦更新發展協會 · 協助監獄更生人及發展監獄事工</p>
    </div>
  </div>
  <section class="section renewal-mission">
    <div class="container renewal-mission-layout">
      <div>
        <p class="eyebrow">關於我們的使命</p>
        <h2>讓盼望，重新發芽</h2>
        <p class="muted">
          每一個走出高牆的人，都值得被看見、被接納。我們相信每段生命都有更新的可能，陪伴是我們所做最真實的事。
        </p>
        <NuxtLink class="button" to="/about">了解協會宗旨 →</NuxtLink>
      </div>
      <div>
        <div class="mission-values">
          <div>
            <b>盼望</b>
            <p>以信仰為根基，支持生命更新。</p>
          </div>
          <div>
            <b>同行</b>
            <p>以長期陪伴，走好下一步。</p>
          </div>
        </div>
        <img
          class="mission-nature"
          :src="
            imageFor(association?.data?.image_id) ||
            '/images/redesign/imgImageNature.png'
          "
          :alt="
            imageFor(association?.data?.image_id)
              ? '協會公開事工影像'
              : '土壤中萌芽的新苗，象徵生命更新'
          "
          loading="lazy"
        />
      </div>
    </div>
  </section>
  <section id="ministry" class="section renewal-ministry">
    <div class="container">
      <p class="eyebrow">OUR MINISTRY · 事工旅程</p>
      <div class="section-heading">
        <h2>把陪伴，走成一條路</h2>
        <p class="muted">四個方向，一份共同的心意。</p>
      </div>
      <ol class="renewal-ministry-grid">
        <li v-for="(item, index) in ministries" :key="item.title">
          <NuxtLink
            :to="item.to"
            :class="[
              'renewal-ministry-card',
              { 'renewal-ministry-card--dark': index === 3 },
            ]"
            :aria-label="`${item.title}｜${item.action}`"
            ><span class="ministry-icon"
              ><img
                :src="`/images/redesign/img${item.icon}.svg`"
                alt="" /></span
            ><small>STEP {{ String(index + 1).padStart(2, "0") }}</small>
            <h3>{{ item.title }}</h3>
            <p>{{ item.body }}</p>
            <span class="ministry-direct-link"
              >{{ item.title }}｜{{ item.action }} ↗</span
            ></NuxtLink
          >
        </li>
      </ol>
    </div>
  </section>
  <FullVerse />
  <section class="section renewal-stories">
    <div class="container">
      <p class="eyebrow">A STORY OF HOPE · 更新故事</p>
      <div class="section-heading">
        <h2>更新的足跡</h2>
        <NuxtLink class="button ghost" to="/news">更多消息與見證 →</NuxtLink>
      </div>
      <p v-if="newsPending" class="empty">載入最新消息中…</p>
      <p v-else-if="newsError" class="notice">
        目前無法取得最新消息，請稍後再試。
      </p>
      <p v-else-if="!latestNews?.data?.length" class="empty">
        目前尚無已發布的消息。
      </p>
      <div v-else class="renewal-story-grid">
        <NuxtLink
          v-for="(article, index) in latestNews.data.slice(0, 3)"
          :key="article.id"
          :to="`/news/${article.id}`"
          class="renewal-story-card"
          ><figure>
            <img
              :src="
                imageFor(article.image_id) ||
                `/images/redesign/imgStoryImage${Number(index) + 1}.png`
              "
              :alt="
                imageFor(article.image_id)
                  ? article.title
                  : '陪伴與更新的情境示意圖'
              "
              loading="lazy"
            />
            <figcaption v-if="!imageFor(article.image_id)">情境示意</figcaption>
          </figure>
          <div class="renewal-story-body">
            <small
              >{{ taipeiDate(article.published_at) }} ·
              {{ article.category || "最新消息" }}</small
            >
            <h3>{{ article.title }}</h3>
            <p class="muted">{{ article.summary }}</p>
            <span>閱讀故事 →</span>
          </div></NuxtLink
        >
      </div>
    </div>
  </section>
  <PublicSupport />
</template>
