<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const users = ref<any[]>([]),
  roles = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null);
const form = reactive<any>({
  name: "",
  phone: "",
  password: "",
  active: true,
  role_ids: [],
});
const { error, run } = useApiError();
const canManageRoles = () => can("roles.manage.all");
async function load() {
  error.value = "";
  try {
    users.value = (await api<any>("/users")).data || [];
    if (canManageRoles()) roles.value = (await api<any>("/roles")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
}
function edit(u?: any) {
  editing.value = u || null;
  Object.assign(form, {
    name: u?.name || "",
    phone: u?.phone || "",
    password: "",
    active: u?.active ?? true,
    role_ids: u?.roles?.map((r: any) => r.id) || [],
  });
  open.value = true;
}
async function save() {
  const body: any = { name: form.name, active: form.active };
  if (!editing.value) {
    body.phone = form.phone;
    body.password = form.password;
    body.role_ids = form.role_ids;
  } else if (canManageRoles()) body.role_ids = form.role_ids;
  await run(() =>
    api(editing.value ? `/users/${editing.value.id}` : "/users", {
      method: editing.value ? "PUT" : "POST",
      body,
    }),
  );
  open.value = false;
  load();
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">ACCESS CONTROL</p>
      <h1>人員與角色</h1>
      <p class="muted">
        使用者可同時擁有多個啟用角色，實際權限由伺服器聯集判斷。
      </p>
    </div>
    <div class="toolbar">
      <button class="button" @click="edit()">新增人員</button
      ><NuxtLink
        v-if="canManageRoles()"
        class="button ghost"
        to="/app/admin/roles"
        >編輯角色權限</NuxtLink
      >
    </div>
  </div>
  <div v-if="error" class="notice">{{ error }}</div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>姓名</th>
          <th>電話</th>
          <th>角色</th>
          <th>啟用</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td>{{ u.name }}</td>
          <td>{{ u.phone }}</td>
          <td>{{ u.roles?.map((r: any) => r.name).join("、") }}</td>
          <td>{{ u.active ? "是" : "否" }}</td>
          <td><button class="button ghost" @click="edit(u)">編輯</button></td>
        </tr>
        <tr v-if="!users.length">
          <td colspan="5" class="empty">尚無可查看的人員。</td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "人員資料" : "新增人員" }}</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <label class="field">姓名<input v-model="form.name" required /></label
      ><label v-if="!editing" class="field"
        >電話<input v-model="form.phone" required /></label
      ><label v-if="!editing" class="field"
        >初始密碼（至少 10 字元）<input
          v-model="form.password"
          type="password"
          minlength="10"
          required /></label
      ><label v-if="canManageRoles()" class="field"
        >角色（可複選）<select v-model="form.role_ids" multiple>
          <option v-for="r in roles" :key="r.id" :value="r.id">
            {{ r.name }}
          </option>
        </select></label
      >
      <p v-else-if="editing" class="notice">
        目前角色：{{
          editing.roles?.map((r: any) => r.name).join("、") || "未指派"
        }}。您沒有角色管理權限，因此不會變更角色。
      </p>
      <label><input v-model="form.active" type="checkbox" /> 啟用帳戶</label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存</button>
    </form>
  </div>
</template>
