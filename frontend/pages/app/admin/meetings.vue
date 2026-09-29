<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const roles = ref<any[]>([]),
  groups = ref<any[]>([]),
  filterGroupId = ref("");
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
    displayKey: "role_names",
    optional: true,
  },
  {
    key: "group_ids",
    label: "可查看小組",
    type: "multiselect",
    options: groups.value,
    optional: true,
    displayKey: "group_names",
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
  category = ref("一般資源"),
  uploadGroupIds = ref<number[]>([]);
const { error, run } = useApiError();
const meetingEndpoint = computed(
  () =>
    `/meetings${filterGroupId.value ? `?group_id=${filterGroupId.value}` : ""}`,
);
async function load() {
  try {
    resources.value =
      (
        await api<any>(
          `/resources${filterGroupId.value ? `?group_id=${filterGroupId.value}` : ""}`,
        )
      ).data || [];
    groups.value = (await api<any>("/groups/options")).data || [];
    if (can("meetings.create.all") || can("meetings.update.all"))
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
  uploadGroupIds.value.forEach((id) => fd.append("group_ids[]", String(id)));
  await run(() => api("/resources", { method: "POST", body: fd }));
  file.value = null;
  title.value = "";
  uploadGroupIds.value = [];
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
    :endpoint="meetingEndpoint"
    :fields="fields"
    :can-create="can('meetings.create.all')"
    :can-update="can('meetings.update.all')"
    description="會議議程、紀錄與決議可依角色或小組存取。"
  />
  <section class="card meeting-filter">
    <label class="field"
      >篩選小組<select v-model="filterGroupId" @change="load">
        <option value="">全部小組</option>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }}
        </option>
      </select></label
    >
  </section>
  <section class="section" style="padding-bottom: 0">
    <h2 class="serif">會議資源與檔案</h2>
    <form
      v-if="can('resources.create.own') || can('resources.create.all')"
      class="card toolbar"
      @submit.prevent="upload"
    >
      <input v-model="title" placeholder="檔案標題" /><input
        v-model="category"
        placeholder="分類"
      /><select v-model="uploadGroupIds" multiple aria-label="分享小組">
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }}
        </option></select
      ><input
        type="file"
        required
        @change="file = ($event.target as HTMLInputElement).files?.[0] || null"
      /><button class="button">上傳資源</button>
    </form>
    <div class="tablewrap" style="margin-top: 12px">
      <table class="table">
        <tr v-for="r in resources" :key="r.id">
          <td data-label="名稱">{{ r.title }}</td>
          <td data-label="分類">{{ r.category }}</td>
          <td data-label="小組">{{ r.group_names?.join("、") || "—" }}</td>
          <td data-label="操作">
            <a
              class="button ghost"
              :href="`/api/v1/files/${r.file_id || r.id}/download`"
              >下載</a
            >
            <button
              v-if="can('resources.delete.all')"
              class="button danger"
              @click="remove(r)"
            >
              刪除
            </button>
          </td>
        </tr>
      </table>
    </div>
    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>
