<script setup lang="ts">
const { data } = await useAsyncData("about-pages", async () => {
  const load = async (slug: string) => {
    try {
      return (await api<any>(`/public/pages/${slug}`)).data;
    } catch {
      return null;
    }
  };
  const [about, history, organization] = await Promise.all(
    ["about", "history", "organization"].map(load),
  );
  return { about, history, organization };
});
const cover = computed(() => {
  const id = data.value?.about?.image_id;
  return Number.isInteger(id) && id > 0 ? `/api/v1/files/${id}/download` : null;
});
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">ABOUT US</div>
      <h1>在恩典裡，重建盼望</h1>
      <p class="muted">以信仰為起點，以陪伴走進每一段生命。</p>
    </div>
  </div>
  <nav class="about-anchor-nav container" aria-label="協會介紹章節">
    <a href="#mission">協會宗旨</a><a href="#history">協會沿革</a
    ><a href="#organization">組織架構</a><a href="#team">同工介紹</a>
  </nav>
  <section id="mission" class="section">
    <div class="container mission-layout">
      <figure class="mission-visual">
        <img v-if="cover" :src="cover" alt="協會公開事工影像" /><template v-else
          ><MinistryIllustration kind="people" />
          <figcaption>陪伴與同行・示意插畫</figcaption></template
        >
      </figure>
      <article>
        <p class="eyebrow">OUR PURPOSE</p>
        <h2>{{ data?.about?.title || "中華復甦更新發展協會" }}</h2>
        <p class="mission-lead">協助監獄更生人及發展監獄事工。</p>
        <RichArticle
          :html="data?.about?.body_html"
          :text="data?.about?.body || '協會介紹內容尚待發布。'"
        />
        <div class="mission-value">
          <span aria-hidden="true">✦</span>
          <p>
            {{
              data?.about?.summary || "尊重每一個人，以長期陪伴支持生命更新。"
            }}
          </p>
        </div>
      </article>
    </div>
  </section>
  <section id="history" class="section tint">
    <div class="container history-layout">
      <div>
        <p class="eyebrow">OUR JOURNEY</p>
        <h2>{{ data?.history?.title || "協會沿革" }}</h2>
        <p class="muted">每一次同行，都留下盼望的足跡。</p>
      </div>
      <article class="history-body">
        <RichArticle
          :html="data?.history?.body_html"
          :text="
            data?.history?.body ||
            data?.history?.summary ||
            '沿革內容尚待發布。'
          "
        />
      </article>
    </div>
  </section>
  <section id="organization" class="section">
    <div class="container">
      <p class="eyebrow">TOGETHER WE SERVE</p>
      <h2>{{ data?.organization?.title || "組織與同工" }}</h2>
      <div class="organization-intro">
        <RichArticle
          :html="data?.organization?.body_html"
          :text="
            data?.organization?.body ||
            data?.organization?.summary ||
            '組織資訊尚待發布。'
          "
        />
      </div>
      <OrganizationOverview :metadata="data?.organization?.metadata" />
    </div>
  </section>
</template>
<style scoped>
.about-anchor-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 24px 0 0;
}
.about-anchor-nav a {
  display: grid;
  place-items: center;
  min-height: 44px;
  padding: 8px 20px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: #fffdf8;
  font-size: 14px;
}
.about-anchor-nav a:hover {
  color: white;
  background: var(--pine);
}
.about-anchor-nav a:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
.section {
  scroll-margin-top: 130px;
}
.mission-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(32px, 6vw, 80px);
  align-items: center;
}
.mission-visual {
  position: relative;
  margin: 0;
  min-width: 0;
  aspect-ratio: 1.08;
  overflow: hidden;
  border-radius: 28px;
  background: #dce5db;
  display: grid;
  place-items: center;
}
.mission-visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.mission-visual :deep(svg) {
  width: 88%;
  max-height: 380px;
}
.mission-visual figcaption {
  position: absolute;
  bottom: 16px;
  font-size: 12px;
  color: #466453;
}
.mission-lead {
  font-size: 20px;
  line-height: 1.8;
}
.mission-value {
  display: flex;
  gap: 16px;
  align-items: center;
  border-top: 1px solid var(--line);
  padding-top: 20px;
  margin-top: 28px;
}
.mission-value span {
  color: #8d6a2d;
  font-size: 28px;
}
.mission-value p {
  margin: 0;
  color: var(--muted);
  font-size: 15px;
}
.history-layout {
  display: grid;
  grid-template-columns: 0.8fr 1.2fr;
  gap: 48px;
}
.history-body {
  padding-left: clamp(0px, 2vw, 24px);
}
.history-body :deep(ol) {
  list-style: none;
  margin: 24px 0;
  padding: 0 0 0 30px;
  border-left: 2px solid #c5d1bf;
}
.history-body :deep(ol > li) {
  position: relative;
  padding: 0 0 28px 8px;
}
.history-body :deep(ol > li:last-child) {
  padding-bottom: 0;
}
.history-body :deep(ol > li::before) {
  content: "";
  position: absolute;
  left: -39px;
  top: 8px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--pine);
  border: 3px solid #f7f5ef;
}
.history-body :deep(ol > li > p:first-child) {
  margin: 0;
  color: #8d6a2d;
  font-size: 14px;
}
.history-body :deep(ol h3) {
  margin: 8px 0;
}
.organization-intro {
  max-width: 760px;
}
@media (max-width: 760px) {
  .mission-layout,
  .history-layout {
    grid-template-columns: 1fr;
    gap: 28px;
  }
  .mission-visual {
    max-height: 340px;
  }
  .about-anchor-nav {
    gap: 8px;
  }
  .about-anchor-nav a {
    padding: 8px 14px;
  }
}
</style>
