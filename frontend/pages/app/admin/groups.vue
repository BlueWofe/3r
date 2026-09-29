<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]),
  options = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  pending = ref(false),
  query = ref(""),
  error = ref(""),
  success = ref("");
const form = reactive<any>({
  name: "",
  description: "",
  active: true,
  member_ids: [],
});
const filtered = computed(() =>
  options.value.filter((person) => `${person.name}`.includes(query.value)),
);
async function load() {
  try {
    const [groups, members] = await Promise.all([
      api<any>("/groups"),
      api<any>("/groups/member-options"),
    ]);
    rows.value = groups.data || [];
    options.value = members.data || [];
    error.value = "";
  } catch (e: any) {
    error.value = e.message || "無法載入小組";
  }
}
function reset(row?: any) {
  editing.value = row || null;
  Object.assign(form, {
    name: row?.name || "",
    description: row?.description || "",
    active: row?.active ?? true,
    member_ids: [
      ...(row?.member_ids || row?.members?.map((m: any) => m.id) || []),
    ],
  });
  query.value = "";
  error.value = "";
  open.value = true;
}
function selectVisible() {
  form.member_ids = [
    ...new Set([
      ...form.member_ids,
      ...filtered.value.filter((p: any) => p.active).map((p: any) => p.id),
    ]),
  ];
}
async function save() {
  if (pending.value) return;
  pending.value = true;
  error.value = "";
  try {
    const body: any = {
      name: form.name,
      description: form.description,
      active: form.active,
      member_ids: [...form.member_ids],
    };
    if (editing.value) body.version = editing.value.version;
    await api(editing.value ? `/groups/${editing.value.id}` : "/groups", {
      method: editing.value ? "PUT" : "POST",
      body,
    });
    open.value = false;
    success.value = "已更新";
    await load();
  } catch (e: any) {
    error.value = e.message || "儲存失敗";
  } finally {
    pending.value = false;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">GROUPS</p>
      <h1>小組管理</h1>
      <p class="muted">
        小組可用於消息、會議與資源；加入不會改變角色權限，停用會保留歷史連結。
      </p>
    </div>
    <button class="button" @click="reset()">新增小組</button>
  </div>
  <p v-if="success" class="notice">{{ success }}</p>
  <p v-if="error && !open" class="error">{{ error }}</p>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>小組</th>
          <th>說明</th>
          <th>成員</th>
          <th>狀態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td data-label="小組">
            <strong>{{ row.name }}</strong>
          </td>
          <td data-label="說明">{{ row.description || "—" }}</td>
          <td data-label="成員">
            {{ row.member_count ?? row.members?.length ?? 0 }} 人
          </td>
          <td data-label="狀態">{{ row.active ? "啟用" : "已停用" }}</td>
          <td data-label="操作">
            <button class="button ghost" @click="reset(row)">編輯</button>
          </td>
        </tr>
        <tr v-if="!rows.length">
          <td colspan="5" class="empty">尚未建立小組。</td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "編輯小組" : "新增小組" }}</h2>
        <button
          class="button ghost"
          type="button"
          :disabled="pending"
          @click="open = false"
        >
          關閉
        </button>
      </div>
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field"
          >小組名稱<input v-model="form.name" required /></label
        ><label class="field"
          >狀態<select v-model="form.active">
            <option :value="true">啟用</option>
            <option :value="false">停用</option>
          </select></label
        >
      </div>
      <label class="field">說明<textarea v-model="form.description" /></label>
      <section class="card">
        <div class="workhead">
          <div>
            <h3>選擇成員</h3>
            <p class="muted">僅能新增啟用的人員；既有停用成員可取消。</p>
          </div>
          <button type="button" class="button ghost" @click="selectVisible">
            全選搜尋結果
          </button>
        </div>
        <label class="field"
          >搜尋成員<input v-model="query" placeholder="依姓名篩選"
        /></label>
        <div class="member-options">
          <label v-for="person in filtered" :key="person.id"
            ><input
              v-model="form.member_ids"
              type="checkbox"
              :value="person.id"
              :disabled="!person.active && !form.member_ids.includes(person.id)"
            />
            {{ person.name
            }}<small v-if="!person.active">（已停用）</small></label
          >
        </div>
      </section>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button" :disabled="pending">
        {{ pending ? "儲存中…" : "儲存小組" }}
      </button>
    </form>
  </div>
</template>
<style scoped>
.member-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  max-height: 260px;
  overflow: auto;
}
.member-options label {
  padding: 8px;
  border: 1px solid var(--line);
  border-radius: 8px;
}
@media (max-width: 760px) {
  .member-options {
    grid-template-columns: 1fr;
  }
}
</style>
