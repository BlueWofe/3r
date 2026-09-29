<script setup lang="ts">
definePageMeta({ layout: "app" });
const roles = ref<any[]>([]);
const fields = computed(() => [
  { key: "title", label: "會議名稱" },
  { key: "meeting_date", label: "日期", type: "date" },
  { key: "agenda", label: "議程", type: "textarea" },
  { key: "minutes", label: "紀錄", type: "textarea" },
  { key: "decisions", label: "決議", type: "textarea" },
  {
    key: "role_ids",
    label: "可查看角色",
    type: "multiselect",
    options: roles.value,
    optional: true,
  },
  {
    key: "file_ids",
    label: "會議附件",
    type: "multiselect",
    options: resources.value.map((r: any) => ({
      id: r.file_id || r.id,
      name: r.title,
    })),
    optional: true,
  },
]);
const resources = ref<any[]>([]),
  file = ref<File | null>(null),
  title = ref(""),
  category = ref("一般資源");
const { error, run } = useApiError();
async function load() {
  try {
    resources.value = (await api<any>("/resources")).data || [];
    roles.value = (await api<any>("/role-options")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
}
async function upload() {
  if (!file.value) return;
  const fd = new FormData();
  fd.append("file", file.value);
  fd.append("title", title.value || file.value.name);
  fd.append("category", category.value);
  await run(() => api("/resources", { method: "POST", body: fd }));
  file.value = null;
  title.value = "";
  load();
}
async function remove(r: any) {
  await run(() => api(`/resources/${r.id}`, { method: "DELETE" }));
  load();
}
onMounted(load);
</script>
<template>
  <EntityBoard
    title="會議管理"
    endpoint="/meetings"
    :fields="fields"
    description="會議議程、紀錄與決議可依角色存取。"
  />
  <section class="section" style="padding-bottom: 0">
    <h2 class="serif">會議資源與檔案</h2>
    <form class="card toolbar" @submit.prevent="upload">
      <input v-model="title" placeholder="檔案標題" /><input
        v-model="category"
        placeholder="分類"
      /><input
        type="file"
        required
        @change="file = ($event.target as HTMLInputElement).files?.[0] || null"
      /><button class="button">上傳資源</button>
    </form>
    <div class="tablewrap" style="margin-top: 12px">
      <table class="table">
        <tr v-for="r in resources" :key="r.id">
          <td>{{ r.title }}</td>
          <td>{{ r.category }}</td>
          <td>
            <a
              class="button ghost"
              :href="`/api/v1/files/${r.file_id || r.id}/download`"
              >下載</a
            >
            <button class="button danger" @click="remove(r)">刪除</button>
          </td>
        </tr>
      </table>
    </div>
    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>
