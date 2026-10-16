<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]);
const { error, run } = useApiError();
const displayChange = (value: any) => value === null || value === undefined || value === '' ? '—' : typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);

async function load() {
  rows.value = (await api<any>("/changes")).data || [];
}
async function ack(r: any) {
  await run(() => api(`/changes/${r.id}/acknowledge`, { method: "POST" }));
  await load();
}
onMounted(load);
</script>
<template>
  <div class="workhead"><div><p class="eyebrow">異動通知</p><h1>每一次異動，都有紀錄</h1><p class="muted">查看服務安排的變更前後資訊，確認最新通知。</p></div></div>
  <div v-if="!rows.length && !error" class="empty">目前沒有異動通知。</div>
  <div class="workspace-stack">
    <article v-for="r in rows" :key="r.id" class="card change-card">
      <div class="change-heading"><span :class="['status', {warning: !r.acknowledged_at}]">{{ r.acknowledged_at ? '已確認' : '待確認' }}</span><small class="muted">{{ r.created_at || r.service_date }}</small></div>
      <h3>{{ r.message || r.reason || r.type }}</h3>
      <div class="change-values"><section><span>變更前</span><pre>{{ displayChange(r.before ?? r.before_value) }}</pre></section><section><span>變更後</span><pre>{{ displayChange(r.after ?? r.after_value) }}</pre></section></div>
      <p v-if="r.reason" class="muted">原因：{{ r.reason }}</p><p v-if="r.actor_name || r.actor?.name" class="muted">異動人：{{ r.actor_name || r.actor?.name }}</p>
      <button v-if="!r.acknowledged_at" class="button" @click="ack(r)">確認已閱</button>
    </article>
  </div>
  <p v-if="error" class="error" role="alert">{{ error }}</p>
</template>
<style scoped>
.change-heading { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; } .change-values { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; } .change-values section { padding:16px; background:var(--r3-paper); border:1px solid var(--r3-border); border-radius:12px; } .change-values section:last-child { background:var(--r3-sage); } .change-values span { font-size:13px; color:var(--r3-muted); } .change-values pre { font:inherit; white-space:pre-wrap; overflow-wrap:anywhere; margin:8px 0 0; } @media(max-width:760px) { .change-values { grid-template-columns:minmax(0,1fr); } }
</style>
