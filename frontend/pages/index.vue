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
      <ol class="journey-timeline">
        <li>
          <article class="card">
            <span class="eyebrow">01</span>
            <h3>走進高牆</h3>
            <p class="muted">
              以監所服務、生命教育與節慶關懷，主動走近需要被聽見的人。
            </p>
            <NuxtLink to="/about">了解服務 →</NuxtLink>
          </article>
        </li>
        <li>
          <article class="card">
            <span class="eyebrow">02</span>
            <h3>建立信任</h3>
            <p class="muted">透過穩定會面與支持關係，讓改變有安全的開始。</p>
            <NuxtLink to="/contact">聯絡我們 →</NuxtLink>
          </article>
        </li>
        <li>
          <article class="card">
            <span class="eyebrow">03</span>
            <h3>預備復歸</h3>
            <p class="muted">
              連結工作、居住、家庭與專業資源，預備走回社會的每一步。
            </p>
            <NuxtLink to="/news">閱讀消息 →</NuxtLink>
          </article>
        </li>
        <li>
          <article class="card">
            <span class="eyebrow">04</span>
            <h3>社區同行</h3>
            <p class="muted">
              回到社區後持續陪伴，讓家庭、教會與在地網絡共同承接新的生活。
            </p>
            <NuxtLink to="/donate">支持事工 →</NuxtLink>
          </article>
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
