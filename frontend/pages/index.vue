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
          <NuxtLink to="/about" class="ministry-panel ministry-panel--door" aria-label="走進高牆：了解監所服務">
            <span class="ministry-number">01 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="door" class="ministry-illustration" />
            <div class="ministry-caption"><h3>走進高牆</h3><p>讓關懷，走進每一個角落。</p></div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink to="/contact" class="ministry-panel ministry-panel--people" aria-label="建立信任：與我們聯絡">
            <span class="ministry-number">02 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="people" class="ministry-illustration" />
            <div class="ministry-caption"><h3>建立信任</h3><p>用傾聽，陪伴生命的改變。</p></div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink to="/news" class="ministry-panel ministry-panel--growth" aria-label="預備復歸：閱讀服務消息">
            <span class="ministry-number">03 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="growth" class="ministry-illustration" />
            <div class="ministry-caption"><h3>預備復歸</h3><p>為重新出發，預備一份力量。</p></div>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink to="/donate" class="ministry-panel ministry-panel--home" aria-label="社區同行：支持陪伴事工">
            <span class="ministry-number">04 / OUR MINISTRY</span>
            <span class="ministry-arrow" aria-hidden="true">↗</span>
            <MinistryIllustration kind="home" class="ministry-illustration" />
            <div class="ministry-caption"><h3>社區同行</h3><p>回家的路，我們一起走。</p></div>
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
        <p class="muted">
          以下為明確標示的示範故事，呈現陪伴關係中可能發生的改變，並非真實個案。
        </p>
        <NuxtLink class="button" to="/news">閱讀最新故事</NuxtLink>
      </div>
      <ol class="story-timeline">
        <li>
          <b>示範故事｜相遇</b><br />在一次關懷活動中，開始願意說出自己的需要。
        </li>
        <li>
          <b>示範故事｜同行</b><br />透過穩定的支持與學習，重新建立生活節奏。
        </li>
        <li>
          <b>示範故事｜連結</b><br />在家庭與社區網絡中，持續練習新的選擇。
        </li>
      </ol>
    </div>
  </section>
</template>
