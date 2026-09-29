<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]),
  loading = ref(true);
const { error } = useApiError();
onMounted(async () => {
  try {
    rows.value = (await api<any>("/resources")).data || [];
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
      <p class="eyebrow">PRIVATE RESOURCES</p>
      <h1>資源下載</h1>
      <p class="muted">檔案下載權限由系統依您的角色判斷。</p>
    </div>
  </div>
  <div v-if="loading" class="empty">載入資源中…</div>
  <div v-else-if="error" class="notice">{{ error }}</div>
  <div v-else-if="!rows.length" class="card empty">目前沒有可下載的資源。</div>
  <div v-else class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>名稱</th>
          <th>分類</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td>{{ r.title }}</td>
          <td>{{ r.category }}</td>
          <td>
            <a
              class="button"
              :href="`/api/v1/files/${r.file_id || r.id}/download`"
              >下載</a
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
