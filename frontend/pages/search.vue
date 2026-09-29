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
  <section class="section">
    <div class="container">
      <div class="eyebrow">SEARCH</div>
      <h1 class="serif">搜尋內容</h1>
      <form class="toolbar" @submit.prevent="search">
        <input
          v-model="q"
          placeholder="輸入關鍵字"
          style="
            flex: 1;
            padding: 11px;
            border: 1px solid var(--line);
            border-radius: 5px;
          "
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
