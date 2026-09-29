<script setup lang="ts">
const { data } = await useAsyncData("about", () =>
  api<any>("/public/pages/about").catch(() => ({ data: null })),
);
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">ABOUT US</div>
      <h1>在恩典裡，重建盼望</h1>
    </div>
  </div>
  <section class="section">
    <div class="container grid" style="grid-template-columns: 1.2fr 0.8fr">
      <article>
        <h2>中華復甦更新發展協會</h2>
        <p class="muted">
          我們是一群相信生命能被更新的人。從監所關懷出發，持續陪伴更生人、家庭與社區，在破碎中建立可走下去的關係。
        </p>
        <p class="muted">
          {{
            data?.data?.body ||
            "這是示範內容。我們以尊重、保密與長期陪伴為原則，串連教會、專業人員及在地資源。"
          }}
        </p>
      </article>
      <aside class="card">
        <h3>我們的價值</h3>
        <p class="muted">
          尊重每一個人<br />看見關係的需要<br />以真實行動回應盼望
        </p>
      </aside>
    </div>
  </section>
  <section class="section tint">
    <div class="container">
      <h2>我們走過的路</h2>
      <div class="grid cards">
        <div class="card">
          <b>2015</b>
          <p class="muted">開始定期監所關懷服務</p>
        </div>
        <div class="card">
          <b>2020</b>
          <p class="muted">建立更生與家庭支持網絡</p>
        </div>
        <div class="card">
          <b>現在</b>
          <p class="muted">持續培訓志工，擴展同行據點</p>
        </div>
      </div>
    </div>
  </section>
</template>
