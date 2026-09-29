<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const rows = ref<any[]>([]),
  roles = ref<any[]>([]),
  groups = ref<any[]>([]),
  loading = ref(true),
  q = ref(""),
  category = ref(""),
  file = ref<File | null>(null),
  title = ref(""),
  uploadCategory = ref("一般資源"),
  roleIds = ref<number[]>([]),
  groupIds = ref<number[]>([]),
  filterGroupId = ref(""),
  editing = ref<any>(null),
  editTitle = ref(""),
  editCategory = ref(""),
  editRoleIds = ref<number[]>([]),
  editGroupIds = ref<number[]>([]),
  driveName = ref(""),
  notice = ref("");
const { error, run } = useApiError();
const canCreate = () =>
  can("resources.create.own") || can("resources.create.all");
const canManage = () =>
  can("resources.update.all") || can("resources.delete.all");
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const result: any = await api(
      `/resources?q=${encodeURIComponent(q.value)}&category=${encodeURIComponent(category.value)}&group_id=${encodeURIComponent(filterGroupId.value)}`,
    );
    rows.value = (result.data || []).filter(
      (r: any) =>
        (!q.value || `${r.title} ${r.category}`.includes(q.value)) &&
        (!category.value || r.category === category.value),
    );
    if (can("resources.create.all"))
      roles.value = (await api<any>("/role-options")).data || [];
    if (canCreate() || can("resources.read.own") || can("resources.read.all"))
      groups.value = (await api<any>("/groups/options")).data || [];
  } catch (e: any) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
async function upload() {
  if (!file.value) return;
  const data = new FormData();
  data.append("file", file.value);
  data.append("title", title.value || file.value.name);
  data.append("category", uploadCategory.value);
  roleIds.value.forEach((id) => data.append("role_ids[]", String(id)));
  groupIds.value.forEach((id) => data.append("group_ids[]", String(id)));
  await run(() => api("/resources", { method: "POST", body: data }));
  notice.value = "資源已上傳";
  file.value = null;
  title.value = "";
  groupIds.value = [];
  roleIds.value = [];
  await load();
}
async function remove(row: any) {
  await run(() => api(`/resources/${row.id}`, { method: "DELETE" }));
  notice.value = "資源已刪除";
  await load();
}
function beginEdit(row: any) {
  editing.value = row;
  editTitle.value = row.title || "";
  editCategory.value = row.category || "";
  editRoleIds.value = [...(row.role_ids || [])];
  editGroupIds.value = [...(row.group_ids || [])];
}
async function saveEdit() {
  if (!editing.value) return;
  await run(() =>
    api(`/resources/${editing.value.id}`, {
      method: "PUT",
      body: {
        title: editTitle.value,
        category: editCategory.value,
        role_ids: editRoleIds.value,
        group_ids: editGroupIds.value,
      },
    }),
  );
  notice.value = "已更新";
  editing.value = null;
  await load();
}
async function drive(action: "upload" | "download") {
  await run(() =>
    api("/integrations/drive/simulate", {
      method: "POST",
      body: { action, name: driveName.value || "示範檔案" },
    }),
  );
  notice.value = `已完成雲端${action === "upload" ? "上傳" : "下載"}模擬`;
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">PRIVATE RESOURCES</p>
      <h1>資源中心</h1>
      <p class="muted">
        分享角色與小組採聯集；兩者都不設定時，維持原有可讀範圍。上傳與雲端操作皆為示範模式。
      </p>
    </div>
    <form class="toolbar" @submit.prevent="load">
      <input v-model="q" placeholder="搜尋資源" /><input
        v-model="category"
        placeholder="分類"
      /><select v-model="filterGroupId" aria-label="篩選小組">
        <option value="">全部小組</option>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }}
        </option></select
      ><button class="button">篩選</button>
    </form>
  </div>
  <form
    v-if="canCreate()"
    class="card form"
    style="margin-bottom: 18px"
    @submit.prevent="upload"
  >
    <h3>上傳資源</h3>
    <p class="muted">可選擇您所屬的小組；只有完整管理權限可指定分享角色。</p>
    <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
      <label class="field"
        >標題<input v-model="title" placeholder="預設使用檔名" /></label
      ><label class="field">分類<input v-model="uploadCategory" /></label>
    </div>
    <label class="field"
      >檔案<input
        type="file"
        required
        @change="
          file = ($event.target as HTMLInputElement).files?.[0] || null
        " /></label
    ><label v-if="can('resources.create.all')" class="field"
      >分享角色（可複選）<select v-model="roleIds" multiple>
        <option v-for="r in roles" :key="r.id" :value="r.id">
          {{ r.name }}
        </option>
      </select></label
    ><label class="field"
      >分享小組（可複選）<select v-model="groupIds" multiple>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }}
        </option></select
      ><small class="muted">可選擇您可管理或所屬的小組。</small></label
    ><button class="button">上傳資源</button>
  </form>
  <div v-if="editing" class="modal">
    <form class="dialog form" @submit.prevent="saveEdit">
      <div class="workhead">
        <h2>編輯資源</h2>
        <button class="button ghost" type="button" @click="editing = null">
          關閉
        </button>
      </div>
      <label class="field">標題<input v-model="editTitle" required /></label
      ><label class="field">分類<input v-model="editCategory" /></label
      ><label class="field"
        >分享角色（可複選）<select v-model="editRoleIds" multiple>
          <option v-for="role in roles" :key="role.id" :value="role.id">
            {{ role.name }}
          </option>
        </select></label
      ><label class="field"
        >分享小組（可複選）<select v-model="editGroupIds" multiple>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }}
          </option>
        </select></label
      ><button class="button">儲存變更</button>
    </form>
  </div>
  <div v-if="canCreate()" class="card form" style="margin-bottom: 18px">
    <h3>雲端硬碟（模擬）</h3>
    <input v-model="driveName" placeholder="檔案名稱" />
    <div class="actions">
      <button class="button" @click="drive('upload')">模擬上傳</button
      ><button class="button ghost" @click="drive('download')">模擬下載</button>
    </div>
  </div>
  <p v-if="notice" class="notice">{{ notice }}</p>
  <div v-if="loading" class="empty">載入資源中…</div>
  <div v-else-if="error" class="notice">{{ error }}</div>
  <div v-else-if="!rows.length" class="card empty">目前沒有可下載的資源。</div>
  <div v-else class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>名稱</th>
          <th>分類</th>
          <th>小組</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td data-label="名稱">{{ r.title }}</td>
          <td data-label="分類">{{ r.category }}</td>
          <td data-label="小組">{{ r.group_names?.join("、") || "—" }}</td>
          <td data-label="操作">
            <a
              class="button"
              :href="`/api/v1/files/${r.file_id || r.id}/download`"
              >下載</a
            >
            <button
              v-if="can('resources.update.all')"
              class="button ghost"
              @click="beginEdit(r)"
            >
              編輯分享範圍
            </button>
            <button v-if="canManage()" class="button danger" @click="remove(r)">
              刪除
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
