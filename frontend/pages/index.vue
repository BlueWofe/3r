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
const { data: association } = await useAsyncData("homepage-association", () =>
  api<any>("/public/pages/about").catch(() => ({ data: null })),
);
const imageFor = (id?: number) =>
  Number.isInteger(id) && Number(id) > 0
    ? `/api/v1/files/${id}/download`
    : null;
const heroImage = computed(() =>
  imageFor(
    latestNews.value?.data?.find((article: any) => imageFor(article.image_id))
      ?.image_id,
  ),
);
</script>
<template>
  <section class="hero">
    <div class="container home-hero-layout">
      <div class="home-hero-copy">
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
      <figure class="home-hero-visual">
        <img v-if="heroImage" :src="heroImage" alt="協會已發布的事工影像" />
        <template v-else
          ><MinistryIllustration kind="door" />
          <figcaption>為生命打開盼望・示意插畫</figcaption></template
        >
      </figure>
    </div>
  </section>
  <section class="section home-welcome">
    <div class="container home-welcome-layout">
      <figure class="home-welcome-visual">
        <img
          v-if="imageFor(association?.data?.image_id)"
          :src="imageFor(association?.data?.image_id)!"
          alt="協會公開影像"
          loading="lazy"
        />
        <template v-else
          ><MinistryIllustration kind="people" />
          <figcaption>
            每一次相遇，都是陪伴的開始・示意插畫
          </figcaption></template
        >
      </figure>
      <div>
        <p class="eyebrow">WE WALK TOGETHER</p>
        <h2>人生的下一步，<br />我們陪你一起走。</h2>
        <p class="muted">
          中華復甦更新發展協會以基督信仰為根基，協助監獄更生人及發展監獄事工。從高牆內的關懷，到重新出發的陪伴，讓每個人都有被傾聽、被支持的機會。
        </p>
        <NuxtLink class="button ghost" to="/about"
          >認識協會 <span aria-hidden="true">↗</span></NuxtLink
        >
      </div>
    </div>
  </section>
  <section class="section">
    <div class="container">
      <div class="eyebrow">OUR MINISTRY</div>
      <h2>陪伴旅程</h2>
      <p class="muted section-lead">
        從走進高牆到社區同行，用關係陪伴每一步更新。
      </p>
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
  <FullVerse />
</template>
<style scoped>
.home-hero-layout {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  align-items: center;
  gap: 48px;
  padding: 64px 0;
}
.home-hero-copy {
  min-width: 0;
}
.home-hero-copy h1 {
  font-size: clamp(34px, 4.2vw, 58px);
  line-height: 1.4;
}
.home-hero-copy .lead {
  font-size: 17px;
  max-width: 520px;
}
.home-hero-visual,
.home-welcome-visual {
  position: relative;
  margin: 0;
  display: grid;
  place-items: center;
  overflow: hidden;
  border-radius: 28px;
  min-width: 0;
  aspect-ratio: 1;
}
.home-hero-visual {
  background: #e9dfc5;
  color: #385747;
  border-top-left-radius: 140px;
}
.home-hero-visual img,
.home-welcome-visual img {
  height: 100%;
  width: 100%;
  object-fit: cover;
}
.home-hero-visual :deep(svg) {
  width: 90%;
}
.home-hero-visual figcaption,
.home-welcome-visual figcaption {
  position: absolute;
  bottom: 24px;
  font-size: 12px;
  color: #4b6456;
  padding: 0 14px;
  text-align: center;
}
.home-welcome {
  background: #fffdf8;
}
.home-welcome-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(32px, 6vw, 80px);
  align-items: center;
}
.home-welcome-visual {
  aspect-ratio: 1.15;
  background: #e6ece3;
  color: #476752;
}
.home-welcome-visual :deep(svg) {
  width: 80%;
}
.home-welcome-layout h2 {
  font-size: clamp(28px, 3.2vw, 40px);
  line-height: 1.55;
}
.home-welcome-layout .button {
  margin-top: 24px;
}
@media (max-width: 760px) {
  .home-hero-layout,
  .home-welcome-layout {
    grid-template-columns: 1fr;
    gap: 32px;
  }
  .home-hero-layout {
    padding: 40px 0 56px;
  }
  .home-hero-visual {
    width: 100%;
    max-height: 300px;
    aspect-ratio: 1.2;
    border-top-left-radius: 90px;
  }
  .home-welcome-visual {
    max-height: 340px;
  }
}
</style>
