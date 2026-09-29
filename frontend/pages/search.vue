<script setup lang="ts">
const q = ref("");
const results = ref<any[]>([]);
const loading = ref(false);
async function search() {
  if (!q.value.trim()) return;
  loading.value = true;
  try {
    const r: any = await api(`/public/search?q=${encodeURIComponent(q.value)}`);
    results.value = r.data || [];
  } finally {
    loading.value = false;
  }
}
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">SEARCH</div>
      <h1>搜尋內容</h1>
    </div>
  </div>
  <section class="section">
    <div class="container">
      <form class="search-panel" @submit.prevent="search">
        <label class="visually-hidden" for="site-search">關鍵字</label>
        <input
          id="site-search"
          v-model="q"
          class="search-field"
          placeholder="輸入關鍵字，例如消息、產品或事工"
        /><button class="button">搜尋</button>
      </form>
      <div v-if="loading" class="empty">搜尋中…</div>
      <div v-else-if="results.length" class="grid" style="margin-top: 25px">
        <article v-for="r in results" :key="r.id" class="card">
          <span class="eyebrow">{{ r.kind || "內容" }}</span>
          <h3>{{ r.title }}</h3>
          <p class="muted">{{ r.summary }}</p>
        </article>
      </div>
      <div v-else-if="q" class="empty">找不到符合的內容。</div>
    </div>
  </section>
</template>
