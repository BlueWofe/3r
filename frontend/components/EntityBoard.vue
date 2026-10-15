<script setup lang="ts">
const p = defineProps<{
  title: string;
  endpoint: string;
  fields: {
    key: string;
    label: string;
    type?: string;
    options?: any[];
    displayKey?: string;
    defaultValue?: any;
    optional?: boolean;
    readonly?: boolean;
    hideInList?: boolean;
  }[];
  description?: string;
  embedded?: boolean;
  cards?: boolean;
  canCreate?: boolean;
  canUpdate?: boolean;
  excludeKinds?: string[];
}>();
const emit = defineEmits<{ updated: [rows: any[]] }>();
const rows = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  form = reactive<any>({});
const files = reactive<Record<string, File | null>>({});
const { error, run } = useApiError();
const saved = ref("");
const pending = ref(false);
const query = ref("");
const visibleRows = computed(() => rows.value.filter(row => !query.value || listFields.value.some(field => String(displayValue(row, field)).toLocaleLowerCase().includes(query.value.toLocaleLowerCase()))));
const listFields = computed(() => p.fields.filter((field) => !field.hideInList));
function displayValue(row: any, field: any) {
  const named = field.displayKey ? row[field.displayKey] : undefined;
  if (named !== undefined && named !== null && named !== "")
    return Array.isArray(named) ? named.join("、") || "—" : named;
  const value = row[field.key];
  if (Array.isArray(value))
    return value.map((item: any) => item.name || field.options?.find((option: any) => String(option.id) === String(item))?.name || item).join("、") || "—";
  return (
    field.options?.find(
      (option: any) => String(option.id ?? option) === String(value),
    )?.name ??
    value ??
    "—"
  );
}
async function load() {
  try {
    const r: any = await api(p.endpoint);
    rows.value = (r.data || []).filter(
      (row: any) => !p.excludeKinds?.includes(row.kind),
    );
    emit("updated", rows.value);
    error.value = "";
  } catch (e: any) {
    error.value = e.message;
  }
}
function newRow() {
  editing.value = null;
  Object.keys(form).forEach((k) => delete form[k]);
  p.fields.forEach(
    (f) =>
      (form[f.key] = f.defaultValue ?? (f.type === "multiselect" ? [] : "")),
  );
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
  if (pending.value) return;
  pending.value = true;
  try {
    const body = { ...form };
    p.fields
      .filter((field) => field.readonly)
      .forEach((field) => delete body[field.key]);
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
      api(
        editing.value
          ? `${p.endpoint.split("?")[0]!}/${editing.value.id}`
          : p.endpoint.split("?")[0]!,
        {
          method: editing.value ? "PUT" : "POST",
          body,
        },
      ),
    );
    open.value = false;
    await load();
    saved.value = "已更新";
  } finally {
    pending.value = false;
  }
}
watch(() => p.endpoint, load);
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p v-if="!embedded" class="eyebrow">管理工作台</p>
      <component :is="embedded ? 'h2' : 'h1'">{{ title }}</component>
      <p v-if="description" class="muted">{{ description }}</p>
    </div>
    <button v-if="canCreate !== false" class="button" @click="newRow">
      新增{{ title.replace(/管理|名冊/g, '') }}
    </button>
  </div>
  <label class="field entity-search">搜尋{{ title }}<input v-model="query" :placeholder="`搜尋${title}中的資料`" type="search" /></label>
  <div class="tablewrap">
    <div class="workspace-section-head"><div><h2>{{ title }}列表</h2><p>依目前授權範圍顯示</p></div><span class="muted">{{ visibleRows.length }} 筆</span></div>
    <table v-if="!cards" class="table">
      <thead>
        <tr>
          <th v-for="f in listFields" :key="f.key">{{ f.label }}</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in visibleRows" :key="r.id">
          <td v-for="f in listFields" :key="f.key" :data-label="f.label">
            {{ displayValue(r, f) }}
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
        <tr v-if="!visibleRows.length">
          <td :colspan="listFields.length + 1" class="empty">目前沒有符合條件的資料</td>
        </tr>
      </tbody>
    </table>
    <div v-else class="entity-card-list"><article v-for="row in visibleRows" :key="row.id" class="card"><div class="workhead"><h3>{{ listFields[0] ? displayValue(row, listFields[0]) : title }}</h3><button v-if="canUpdate !== false" class="button ghost" @click="edit(row)">編輯</button></div><dl class="entity-card-fields"><div v-for="field in listFields.slice(1)" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ displayValue(row,field) }}</dd></div></dl></article><div v-if="!visibleRows.length" class="empty">目前沒有符合條件的資料</div></div>
    <footer class="workspace-list-footer">共 {{ visibleRows.length }} 筆{{ title }}</footer>
  </div>
  <p v-if="error && !open" class="notice">{{ error }}</p>
  <p v-if="saved" class="notice">{{ saved }}</p>
  <div v-if="open" class="modal" @keydown.esc="!pending && (open = false)">
    <form class="dialog form entity-dialog" role="dialog" aria-modal="true" :aria-label="`${editing ? '編輯' : '新增'}${title}`" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "編輯" : "新增" }}{{ title }}</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <div class="entity-form-grid">
      <label v-for="f in fields" :key="f.key" :class="['field', { 'entity-field-wide': ['textarea', 'multiselect', 'file'].includes(f.type || '') }]"
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
          <option v-if="f.optional && f.type !== 'multiselect'" value="">
            未選擇
          </option>
          <option
            v-for="o in f.options"
            :key="o.id ?? o"
            :value="o.id ?? o"
            :disabled="o.active === false && o.id !== editing?.[f.key]"
          >
            {{ o.name ?? o }}{{ o.active === false ? "（已停用）" : "" }}
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
      </div>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <button class="button" :disabled="pending">
        {{ pending ? "儲存中…" : "儲存" }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.entity-card-list { padding:0 24px 24px; display:grid; gap:16px; } .entity-card-fields { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin:0; } .entity-card-fields dt { font-size:13px; color:var(--r3-muted); } .entity-card-fields dd { margin:4px 0 0; } .entity-card-list .workhead h3 { margin:0; } @media(max-width:760px) { .entity-card-list { padding:0; } .entity-card-fields { grid-template-columns:minmax(0,1fr); } }
.entity-search { max-width:480px; margin-bottom:24px; } .entity-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; } .entity-field-wide { grid-column:1/-1; } @media(max-width:760px) { .entity-form-grid { grid-template-columns:minmax(0,1fr); } }
</style>
