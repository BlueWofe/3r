<script setup lang="ts">
const p = defineProps<{
  title: string;
  endpoint: string;
  fields: {
    key: string;
    label: string;
    type?: string;
    options?: any[];
    optional?: boolean;
    readonly?: boolean;
  }[];
  description?: string;
  canCreate?: boolean;
  canUpdate?: boolean;
  excludeKinds?: string[];
}>();
const rows = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  form = reactive<any>({});
const files = reactive<Record<string, File | null>>({});
const { error, run } = useApiError();
async function load() {
  try {
    const r: any = await api(p.endpoint);
    rows.value = (r.data || []).filter(
      (row: any) => !p.excludeKinds?.includes(row.kind),
    );
    error.value = "";
  } catch (e: any) {
    error.value = e.message;
  }
}
function newRow() {
  editing.value = null;
  Object.keys(form).forEach((k) => delete form[k]);
  p.fields.forEach((f) => (form[f.key] = f.type === "multiselect" ? [] : ""));
  Object.keys(files).forEach((key) => delete files[key]);
  open.value = true;
}
function edit(r: any) {
  editing.value = r;
  Object.keys(form).forEach((k) => delete form[k]);
  p.fields.forEach(
    (f) => (form[f.key] = r[f.key] ?? (f.type === "multiselect" ? [] : "")),
  );
  Object.keys(files).forEach((key) => delete files[key]);
  open.value = true;
}
async function save() {
  const body = { ...form };
  for (const field of p.fields.filter((f) => f.type === "file")) {
    const file = files[field.key];
    if (!file) continue;
    const data = new FormData();
    data.append("file", file);
    data.append("visibility", "public");
    data.append("title", file.name);
    const uploaded: any = await run(() =>
      api("/files", { method: "POST", body: data }),
    );
    body[field.key] = uploaded.id;
  }
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
    <button v-if="canCreate !== false" class="button" @click="newRow">
      新增
    </button>
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
          <td v-for="f in fields" :key="f.key" :data-label="f.label">
            {{
              Array.isArray(r[f.key])
                ? r[f.key].map((x: any) => x.name || x).join("、")
                : r[f.key]
            }}
          </td>
          <td data-label="操作">
            <button
              v-if="canUpdate !== false"
              class="button ghost"
              @click="edit(r)"
            >
              編輯
            </button>
          </td>
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
          :disabled="f.readonly"
        ></textarea
        ><select
          v-else-if="f.type === 'select' || f.type === 'multiselect'"
          v-model="form[f.key]"
          :multiple="f.type === 'multiselect'"
          :disabled="f.readonly"
        >
          <option v-for="o in f.options" :key="o.id ?? o" :value="o.id ?? o">
            {{ o.name ?? o }}
          </option></select
        ><input
          v-else-if="f.type === 'file'"
          type="file"
          accept="image/*"
          :disabled="f.readonly"
          @change="
            files[f.key] =
              ($event.target as HTMLInputElement).files?.[0] || null
          " /><input
          v-else
          v-model="form[f.key]"
          :type="f.type || 'text'"
          :required="!f.optional"
          :disabled="f.readonly"
      /></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存</button>
    </form>
  </div>
</template>
