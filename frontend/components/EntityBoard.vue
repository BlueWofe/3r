<script setup lang="ts">
const p = defineProps<{
  title: string;
  endpoint: string;
  fields: { key: string; label: string; type?: string; options?: string[] }[];
  description?: string;
}>();
const rows = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  form = reactive<any>({});
const { error, run } = useApiError();
async function load() {
  const r: any = await api(p.endpoint);
  rows.value = r.data || [];
}
function newRow() {
  editing.value = null;
  Object.keys(form).forEach((k) => delete form[k]);
  p.fields.forEach((f) => (form[f.key] = ""));
  open.value = true;
}
function edit(r: any) {
  editing.value = r;
  Object.keys(form).forEach((k) => delete form[k]);
  p.fields.forEach((f) => (form[f.key] = r[f.key] ?? ""));
  open.value = true;
}
async function save() {
  const body = { ...form };
  if (editing.value?.version) body.version = editing.value.version;
  await run(() =>
    api(editing.value ? `${p.endpoint}/${editing.value.id}` : p.endpoint, {
      method: editing.value ? "PUT" : "POST",
      body,
    }),
  );
  open.value = false;
  await load();
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">WORKBENCH</p>
      <h1>{{ title }}</h1>
      <p v-if="description" class="muted">{{ description }}</p>
    </div>
    <button class="button" @click="newRow">新增</button>
  </div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th v-for="f in fields" :key="f.key">{{ f.label }}</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td v-for="f in fields" :key="f.key">
            {{
              Array.isArray(r[f.key])
                ? r[f.key].map((x: any) => x.name || x).join("、")
                : r[f.key]
            }}
          </td>
          <td><button class="button ghost" @click="edit(r)">編輯</button></td>
        </tr>
        <tr v-if="!rows.length">
          <td :colspan="fields.length + 1" class="empty">尚無資料</td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "編輯" : "新增" }}{{ title }}</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <label v-for="f in fields" :key="f.key" class="field"
        >{{ f.label
        }}<textarea
          v-if="f.type === 'textarea'"
          v-model="form[f.key]"
        ></textarea
        ><select v-else-if="f.type === 'select'" v-model="form[f.key]">
          <option v-for="o in f.options" :key="o" :value="o">
            {{ o }}
          </option></select
        ><input v-else v-model="form[f.key]" :type="f.type || 'text'" required
      /></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存</button>
    </form>
  </div>
</template>
