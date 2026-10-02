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
  <div v-else class="grid cards">
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
    </article>
  </div>
</template>
