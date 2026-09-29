<script setup lang="ts">
definePageMeta({ layout: "app" });
const roles = ref<any[]>([]),
  permissions = ref<string[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  form = reactive<any>({ name: "", slug: "", active: true, permissions: [] });
const { error, run } = useApiError();
async function load() {
  [roles.value, permissions.value] = await Promise.all([
    api<any>("/roles").then((x) => x.data || []),
    api<any>("/permissions").then((x) => x.data || []),
  ]);
}
function edit(r?: any) {
  editing.value = r || null;
  Object.assign(form, {
    name: r?.name || "",
    slug: r?.slug || "",
    active: r?.active ?? true,
    permissions: r?.permissions || [],
  });
  open.value = true;
}
async function save() {
  await run(() =>
    api(editing.value ? `/roles/${editing.value.id}` : "/roles", {
      method: editing.value ? "PUT" : "POST",
      body: { ...form },
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
      <p class="eyebrow">ROLE POLICY</p>
      <h1>角色與權限</h1>
    </div>
    <button class="button" @click="edit()">新增角色</button>
  </div>
  <div class="grid cards">
    <article v-for="r in roles" :key="r.id" class="card">
      <span class="status">{{ r.active ? "啟用" : "停用" }}</span>
      <h3>{{ r.name }}</h3>
      <p class="muted">
        {{ r.slug }}<br />{{ r.permissions?.length || 0 }} 項權限
      </p>
      <button class="button ghost" @click="edit(r)">設定權限</button>
    </article>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>角色設定</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <label class="field">名稱<input v-model="form.name" required /></label
      ><label class="field">代稱<input v-model="form.slug" required /></label
      ><label><input v-model="form.active" type="checkbox" /> 啟用角色</label>
      <div class="card">
        <b>權限</b>
        <p class="muted">
          預覽：已選 {{ form.permissions.length }} 項 —
          {{ form.permissions.join("、") || "尚未選擇權限" }}
        </p>
        <div
          class="grid"
          style="grid-template-columns: repeat(2, 1fr); margin-top: 12px"
        >
          <label v-for="p in permissions" :key="p"
            ><input v-model="form.permissions" type="checkbox" :value="p" />
            {{ p }}</label
          >
        </div>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存角色</button>
    </form>
  </div>
</template>
