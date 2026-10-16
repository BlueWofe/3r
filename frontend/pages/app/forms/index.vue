<script setup lang="ts">
definePageMeta({ layout: "app" });
const forms = ref<any[]>([]),
  loading = ref(true);
const { error } = useApiError();
onMounted(async () => {
  try {
    forms.value = (await api<any>("/forms")).data || [];
  } catch (e: any) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">MY FORMS</p>
      <h1>我的表單</h1>
      <p class="muted">只會列出您可填寫的已發布表單。</p>
    </div>
  </div>
  <div v-if="loading" class="empty">載入表單中…</div>
  <div v-else-if="error" class="notice">{{ error }}</div>
  <div v-else-if="!forms.length" class="card empty">目前沒有待填表單。</div>
  <div v-else class="grid cards assigned-form-list">
    <article
      v-for="f in forms.filter((x) => x.status === 'published')"
      :key="f.id"
      class="card"
    >
      <span class="status">已發布</span>
      <h3>{{ f.title }}</h3>
      <p class="muted">
        {{ f.description }}<br /><span v-if="f.deadline"
          >截止：{{ f.deadline }}</span
        >
      </p>
      <NuxtLink class="button" :to="`/app/forms/${f.id}`">開始填寫</NuxtLink>
      <p class="assigned-form-note">請依表單說明填寫，附件依權限保存。</p>
    </article>
  </div>
</template>

<style scoped>.assigned-form-list { grid-template-columns:minmax(0,1fr); } .assigned-form-list .card { display:flex; flex-direction:column; align-items:start; gap:12px; } .assigned-form-list h3,.assigned-form-list p { margin:0; } .assigned-form-list .button { margin-top:auto; } .assigned-form-note { font-size:13px; color:var(--r3-muted); }</style>
