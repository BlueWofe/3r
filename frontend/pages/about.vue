<script setup lang="ts">
const { data, error } = await useAsyncData("about-pages", async () => {
  const [about, history, organization] = await Promise.all(
    [
      "/public/pages/about",
      "/public/pages/history",
      "/public/pages/organization",
    ].map((path) => api<any>(path).catch(() => ({ data: null }))),
  );
  return {
    about: about.data,
    history: history.data,
    organization: organization.data,
  };
});
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">ABOUT US</div>
      <h1>在恩典裡，重建盼望</h1>
    </div>
  </div>
  <nav class="about-anchor-nav container" aria-label="協會介紹章節">
    <a href="#mission">宗旨</a><a href="#history">沿革</a
    ><a href="#organization">組織與同工</a>
  </nav>
  <section id="mission" class="section">
    <div class="container grid responsive-two about-layout">
      <article>
        <p class="eyebrow">協會宗旨</p>
        <h2>{{ data?.about?.title || "中華復甦更新發展協會" }}</h2>
        <RichArticle
          :html="data?.about?.body_html"
          :text="data?.about?.body || '協會資料載入中。'"
        />
      </article>
      <aside class="card value-card">
        <h3>我們的價值</h3>
        <p class="muted">
          {{ data?.about?.summary || "尊重每一個人，以長期陪伴支持生命更新。" }}
        </p>
      </aside>
    </div>
  </section>
  <section id="history" class="section tint">
    <div class="container">
      <p class="eyebrow">HISTORY</p>
      <h2>{{ data?.history?.title || "沿革" }}</h2>
      <RichArticle
        :html="data?.history?.body_html"
        :text="
          data?.history?.body || data?.history?.summary || '沿革內容尚待發布。'
        "
      />
    </div>
  </section>
  <section id="organization" class="section">
    <div class="container">
      <p class="eyebrow">ORGANIZATION</p>
      <h2>{{ data?.organization?.title || "組織與同工" }}</h2>
      <RichArticle
        :html="data?.organization?.body_html"
        :text="
          data?.organization?.body ||
          data?.organization?.summary ||
          '組織資訊尚待發布。'
        "
      />
      <div
        v-if="data?.organization?.metadata?.departments?.length"
        class="grid cards"
      >
        <article
          v-for="item in data.organization.metadata.departments"
          :key="item.name"
          class="card"
        >
          <h3>{{ item.name }}</h3>
          <p class="muted">{{ item.description }}</p>
        </article>
      </div>
      <div v-if="data?.organization?.metadata?.team?.length" class="grid cards">
        <article
          v-for="member in data.organization.metadata.team"
          :key="member.name"
          class="card"
        >
          <span class="eyebrow">{{ member.role }}</span>
          <h3>{{ member.name }}</h3>
          <p class="muted">{{ member.bio }}</p>
        </article>
      </div>
      <p v-else class="notice">同工介紹待協會補充。</p>
      <p v-if="error" class="notice">部分協會資訊目前無法取得。</p>
    </div>
  </section>
</template>
