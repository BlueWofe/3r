<script setup lang="ts">
definePageMeta({ layout: "app" });
type FieldType = "text" | "textarea" | "number" | "date" | "select" | "multiselect" | "file";
type FormField = { key: string; label: string; type: FieldType; required: boolean; options?: string[] };
type FormSnapshot = { version: number; fields: FormField[]; published_at?: string };
type FormRecord = { id: number; title: string; description?: string; status: "draft" | "published"; deadline?: string | null; role_ids?: number[]; fields: FormField[]; version?: number; snapshots?: FormSnapshot[] };
type FormResponse = { id: number; owner_id: number; version: number; answers: Record<string, unknown>; created_at: string };
type View = "list" | "design" | "responses" | "versions";

const route = useRoute();
const { can } = useAuth();
const forms = ref<FormRecord[]>([]);
const roles = ref<{ id: number; name: string }[]>([]);
const selected = ref<FormRecord | null>(null);
const responses = ref<FormResponse[]>([]);
const view = ref<View>("list");
const loading = ref(false), saving = ref(false), responseLoading = ref(false);
const error = ref(""), responseError = ref("");
const search = ref(""), versionFilter = ref("all");
const responseMode = ref<"summary" | "answers">("summary");
const editor = reactive({ title: "", description: "", status: "draft" as "draft" | "published", deadline: "", role_ids: [] as number[], fields: [] as FormField[] });
const fieldTypes: { value: FieldType; label: string }[] = [
  { value: "text", label: "短文字" }, { value: "textarea", label: "長文字" },
  { value: "number", label: "數字" }, { value: "date", label: "日期" },
  { value: "select", label: "單選" }, { value: "multiselect", label: "多選" },
  { value: "file", label: "附件" },
];
const canRead = computed(() => can("forms.read.all") || can("forms.read.own"));
const canManage = computed(() => can("forms.create.all") || can("forms.update.all"));
const versions = computed(() => [...new Set(responses.value.map((item) => item.version))].sort((a, b) => b - a));
const filteredResponses = computed(() => responses.value.filter((item) => {
  if (versionFilter.value !== "all" && item.version !== Number(versionFilter.value)) return false;
  const needle = search.value.trim().toLocaleLowerCase();
  return !needle || [item.id, item.owner_id, ...Object.values(item.answers || {})]
    .some((value) => String(Array.isArray(value) ? value.join(" ") : value ?? "").toLocaleLowerCase().includes(needle));
}));
const statusLabel = (status: string) => status === "published" ? "已發布" : "草稿";
function dateText(value?: string | null, time = false) {
  if (!value) return "未設定";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("zh-TW", { timeZone: "Asia/Taipei", year: "numeric", month: "2-digit", day: "2-digit", ...(time ? { hour: "2-digit", minute: "2-digit" } : {}) }).format(date);
}
function roleText(item: FormRecord) {
  return item.role_ids?.length ? item.role_ids.map((id) => roles.value.find((role) => role.id === id)?.name || `角色 #${id}`).join("、") : "依表單權限";
}
function responseFields(item: FormResponse): FormField[] {
  const snapshot = selected.value?.snapshots?.find((record) => Number(record.version) === Number(item.version));
  if (snapshot?.fields) return snapshot.fields;
  return selected.value?.version === item.version ? selected.value.fields || [] : [];
}
function answers(item: FormResponse) {
  const fields = responseFields(item);
  const keys = [...fields.map((field) => field.key), ...Object.keys(item.answers || {}).filter((key) => !fields.some((field) => field.key === key))];
  return keys.map((key) => ({ key, value: item.answers?.[key], field: fields.find((field) => field.key === key) }));
}
function valueText(value: unknown) {
  if (value === null || value === undefined || value === "") return "未填寫";
  if (Array.isArray(value)) return value.map(String).join("、") || "未填寫";
  if (typeof value === "object") return Object.values(value).map(String).join("、") || "未填寫";
  return String(value);
}
function summary(item: FormResponse) {
  return answers(item).find((answer) => answer.field?.type !== "file" && answer.value !== null && answer.value !== undefined && answer.value !== "");
}
async function load() {
  if (!canRead.value) return;
  loading.value = true;
  try {
    forms.value = (await api<{ data: FormRecord[] }>("/forms")).data || [];
    if (canManage.value) roles.value = (await api<{ data: { id: number; name: string }[] }>("/role-options")).data || [];
    error.value = "";
  } catch (cause: any) { error.value = cause.message; }
  finally { loading.value = false; }
}
function changeView(next: View, item?: FormRecord | null) {
  view.value = next;
  selected.value = item || null;
  const query = next === "list" ? {} : item ? { view: next, form_id: String(item.id) } : { view: next };
  void navigateTo({ path: route.path, query });
}
function edit(item?: FormRecord) {
  Object.assign(editor, { title: item?.title || "", description: item?.description || "", status: item?.status || "draft",
    deadline: item?.deadline?.slice(0, 10) || "", role_ids: [...(item?.role_ids || [])], fields: JSON.parse(JSON.stringify(item?.fields || [])) });
  error.value = "";
  changeView("design", item);
}
function addField() {
  let number = editor.fields.length + 1;
  while (editor.fields.some((field) => field.key === `field_${number}`)) number++;
  editor.fields.push({ key: `field_${number}`, label: "", type: "text", required: false, options: [] });
}
function moveField(index: number, amount: number) {
  const target = index + amount;
  if (target < 0 || target >= editor.fields.length) return;
  const [field] = editor.fields.splice(index, 1);
  if (field) editor.fields.splice(target, 0, field);
}
async function save() {
  if (saving.value || (selected.value ? !can("forms.update.all") : !can("forms.create.all"))) return;
  saving.value = true; error.value = "";
  try {
    const body = { title: editor.title.trim(), description: editor.description.trim(), status: editor.status,
      deadline: editor.deadline || null, role_ids: [...editor.role_ids],
      fields: editor.fields.map((field) => ({ key: field.key.trim(), label: field.label.trim(), type: field.type,
        required: field.required, options: field.type === "select" || field.type === "multiselect" ? field.options || [] : [] })) };
    await api(selected.value ? `/forms/${selected.value.id}` : "/forms", { method: selected.value ? "PUT" : "POST", body });
    await load();
    changeView("list");
  } catch (cause: any) { error.value = cause.message; }
  finally { saving.value = false; }
}
async function show(item: FormRecord, destination: "responses" | "versions") {
  changeView(destination, item);
  responses.value = []; responseError.value = ""; search.value = ""; versionFilter.value = "all"; responseMode.value = "summary";
  if (!can("forms.read.all")) return;
  responseLoading.value = true;
  try {
    const [fresh, result] = await Promise.all([
      api<FormRecord>(`/forms/${item.id}`),
      api<{ data: FormResponse[] }>(`/forms/${item.id}/responses`),
    ]);
    selected.value = fresh; responses.value = result.data || [];
  } catch (cause: any) { responseError.value = cause.message; }
  finally { responseLoading.value = false; }
}
onMounted(async () => {
  await load();
  const id = Number(route.query.form_id);
  const item = forms.value.find((record) => record.id === id);
  const target = String(route.query.view || "");
  if (target === "design" && (item ? can("forms.update.all") : can("forms.create.all"))) edit(item);
  else if (item && (target === "responses" || target === "versions")) await show(item, target);
});
</script>

<template>
  <div class="forms-page">
    <nav class="forms-breadcrumb" aria-label="所在位置">
      <NuxtLink to="/app">我的服務</NuxtLink><span>/</span>
      <button v-if="view !== 'list'" type="button" @click="changeView('list')">表單中心</button>
      <span v-else>表單中心</span>
    </nav>

    <template v-if="view === 'list'">
      <header class="forms-header"><h1>表單中心</h1><p>管理表單版本、發布狀態、截止時間與回覆。</p></header>
      <div class="forms-section-heading"><h2>表單清單</h2><span>共 {{ forms.length }} 筆表單</span></div>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="loading" class="forms-empty">正在載入表單…</p>
      <p v-else-if="!forms.length" class="forms-empty">目前還沒有表單。</p>
      <div v-else class="forms-list">
        <article v-for="item in forms" :key="item.id" class="forms-card">
          <div class="forms-card-heading"><div><h3>{{ item.title }}</h3><p>版本 {{ item.version || 1 }} · {{ statusLabel(item.status) }}<span v-if="item.deadline"> · 截止 {{ dateText(item.deadline) }}</span></p></div><span class="forms-pill" :class="item.status">{{ statusLabel(item.status) }}</span></div>
          <div class="forms-mobile-pills"><span class="forms-pill">版本 {{ item.version || 1 }}</span><span class="forms-pill" :class="item.status">{{ statusLabel(item.status) }}</span><span v-if="item.deadline" class="forms-pill">截止 {{ dateText(item.deadline) }}</span></div>
          <p v-if="item.description" class="forms-description">{{ item.description }}</p>
          <dl class="forms-card-meta"><div><dt>目前版本</dt><dd>版本 {{ item.version || 1 }}</dd></div><div><dt>截止時間</dt><dd>{{ dateText(item.deadline) }}</dd></div><div><dt>填寫對象</dt><dd>{{ roleText(item) }}</dd></div><div><dt>欄位數</dt><dd>{{ item.fields?.length || 0 }} 個</dd></div></dl>
          <div class="forms-card-actions">
            <button v-if="can('forms.read.all')" class="button" type="button" @click="show(item, 'responses')">回覆與版本</button>
            <button v-else-if="can('forms.export.all')" class="button" type="button" @click="show(item, 'versions')">版本資訊</button>
            <div v-if="can('forms.update.all') || item.status === 'published' || can('forms.export.all')" class="forms-secondary-actions">
              <button v-if="can('forms.update.all')" class="button ghost" type="button" @click="edit(item)">設計表單</button>
              <NuxtLink v-if="item.status === 'published'" class="button ghost" :to="`/app/forms/${item.id}`">填寫</NuxtLink>
              <a v-if="can('forms.export.all')" class="button ghost" :href="`/api/v1/forms/${item.id}/export`">匯出 CSV</a>
            </div>
            <details v-if="can('forms.update.all') || item.status === 'published' || can('forms.export.all')" class="forms-more-actions"><summary>更多操作</summary><div>
              <button v-if="can('forms.update.all')" class="button ghost" type="button" @click="edit(item)">設計表單</button>
              <NuxtLink v-if="item.status === 'published'" class="button ghost" :to="`/app/forms/${item.id}`">填寫</NuxtLink>
              <a v-if="can('forms.export.all')" class="button ghost" :href="`/api/v1/forms/${item.id}/export`">匯出 CSV</a>
            </div></details>
          </div>
        </article>
      </div>
      <button v-if="can('forms.create.all')" class="button forms-create" type="button" @click="edit()">建立表單</button>
    </template>

    <template v-else-if="view === 'design'">
      <header class="forms-header"><p class="forms-kicker">設計工作台</p><h1>設計表單與版本</h1><p>編輯表單內容、必填條件與填寫對象，並確認目前狀態與截止日期。</p></header>
      <form class="forms-layout" @submit.prevent="save">
        <section class="forms-panel forms-designer">
          <span class="forms-pill" :class="editor.status">{{ statusLabel(editor.status) }}<span v-if="editor.deadline"> · {{ dateText(editor.deadline) }}</span></span>
          <h2>表單內容</h2><p class="forms-help">發布後，系統會保存此版本的欄位快照，讓舊回覆仍以原本的題目顯示。</p>
          <label class="field">表單標題<input v-model="editor.title" required maxlength="200" /></label>
          <label class="field">說明<textarea v-model="editor.description" rows="3"></textarea></label>
          <div class="forms-two-fields"><label class="field">狀態<select v-model="editor.status"><option value="draft">草稿</option><option value="published">已發布</option></select></label><label class="field">截止日期<input v-model="editor.deadline" type="date" /></label></div>
          <fieldset class="forms-roles"><legend>填寫角色</legend><p class="forms-help">不選取角色時，依既有表單權限提供填寫。</p><label v-for="role in roles" :key="role.id"><input v-model="editor.role_ids" type="checkbox" :value="role.id" />{{ role.name }}</label><p v-if="!roles.length" class="forms-help">目前沒有可選擇的角色。</p></fieldset>
          <div class="forms-fields-heading"><div><h3>欄位設定</h3><p class="forms-help">文字、數字、日期、單多選與附件</p></div><button class="button ghost" type="button" @click="addField">新增欄位</button></div>
          <div v-if="!editor.fields.length" class="forms-empty">尚未新增欄位。請加入第一個題目。</div>
          <div v-for="(field, index) in editor.fields" :key="index" class="forms-field-card">
            <div class="forms-field-top"><h4>欄位 {{ index + 1 }}</h4><div class="forms-field-actions"><button type="button" :disabled="index === 0" @click="moveField(index, -1)">上移</button><button type="button" :disabled="index === editor.fields.length - 1" @click="moveField(index, 1)">下移</button><button type="button" class="forms-remove" @click="editor.fields.splice(index, 1)">移除</button></div></div>
            <div class="forms-two-fields"><label class="field">欄位鍵值<input v-model="field.key" required pattern="[A-Za-z0-9_-]+" /></label><label class="field">題目名稱<input v-model="field.label" required /></label></div>
            <div class="forms-two-fields"><label class="field">欄位類型<select v-model="field.type"><option v-for="type in fieldTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label><label class="forms-check"><input v-model="field.required" type="checkbox" />必填</label></div>
            <label v-if="field.type === 'select' || field.type === 'multiselect'" class="field">選項（以逗號分隔）<input :value="(field.options || []).join(', ')" @input="field.options = ($event.target as HTMLInputElement).value.split(',').map((option) => option.trim()).filter(Boolean)" /></label>
          </div>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <div class="forms-editor-actions"><button class="button" type="submit" :disabled="saving">{{ saving ? '儲存中…' : editor.status === 'published' ? '發布新版本' : '儲存草稿版本' }}</button><button class="button ghost" type="button" @click="changeView('list')">取消</button></div>
        </section>
        <aside class="forms-aside"><section class="forms-panel"><h3>版本資訊</h3><dl class="forms-info"><div><dt>目前版本</dt><dd>{{ selected?.version ? `版本 ${selected.version}` : '新表單' }}</dd></div><div><dt>填寫角色</dt><dd>{{ editor.role_ids.length ? editor.role_ids.map((id) => roles.find((role) => role.id === id)?.name || `角色 #${id}`).join('、') : '依表單權限' }}</dd></div><div><dt>欄位設定</dt><dd>{{ editor.fields.length }} 個欄位</dd></div></dl></section><section class="forms-panel"><h3>狀態與操作</h3><dl class="forms-info"><div><dt>狀態</dt><dd>{{ statusLabel(editor.status) }}</dd></div><div><dt>截止</dt><dd>{{ dateText(editor.deadline) }}</dd></div><div><dt>操作</dt><dd>儲存時建立新版本</dd></div></dl></section></aside>
      </form>
    </template>

    <template v-else-if="selected">
      <header class="forms-header"><p class="forms-kicker">管理工作台</p><h1>{{ view === 'versions' ? '表單版本' : '讀懂每一份回覆' }}</h1><p>{{ selected.title }} · 以清晰的摘要、篩選與版本資訊，快速掌握回覆內容、附件與欄位答案。</p></header>
      <div class="forms-tabs" role="tablist" aria-label="表單資訊"><button type="button" role="tab" :aria-selected="view === 'responses'" :class="{ active: view === 'responses' }" @click="show(selected!, 'responses')">回覆</button><button type="button" role="tab" :aria-selected="view === 'versions'" :class="{ active: view === 'versions' }" @click="show(selected!, 'versions')">版本</button></div>
      <p v-if="responseError" class="error" role="alert">{{ responseError }}</p>
      <div class="forms-layout">
        <div class="forms-main-column">
          <template v-if="view === 'responses'">
            <section class="forms-panel"><div class="forms-panel-heading"><div><p class="forms-kicker">回覆摘要</p><h2>快速檢視回覆核心內容</h2><p class="forms-help">共 {{ filteredResponses.length }} 份符合條件的回覆。</p></div><span class="forms-pill">唯讀</span></div><div class="forms-filters"><label class="field">搜尋回覆<input v-model="search" type="search" placeholder="搜尋編號或答案" /></label><label class="field">版本<select v-model="versionFilter"><option value="all">所有版本</option><option v-for="number in versions" :key="number" :value="String(number)">版本 {{ number }}</option></select></label></div><div class="forms-segmented"><button type="button" :class="{ active: responseMode === 'summary' }" @click="responseMode = 'summary'">回覆摘要</button><button type="button" :class="{ active: responseMode === 'answers' }" @click="responseMode = 'answers'">附件與欄位答案</button></div></section>
            <p v-if="responseLoading" class="forms-empty">正在載入回覆…</p>
            <p v-else-if="!can('forms.read.all')" class="forms-empty">目前沒有查看所有回覆的權限。</p>
            <p v-else-if="!filteredResponses.length" class="forms-empty">目前沒有符合條件的回覆。</p>
            <section v-else class="forms-panel"><h2>資料清單</h2><p class="forms-help">每份回覆依提交時的表單版本顯示欄位名稱。</p>
              <article v-for="response in filteredResponses" :key="response.id" class="forms-response"><header><div><h3>回覆 #{{ response.id }} · 版本 {{ response.version }}</h3><p>填答者 #{{ response.owner_id }} · {{ dateText(response.created_at, true) }} 提交</p></div><span class="forms-pill">版本 {{ response.version }}</span></header>
                <p v-if="responseMode === 'summary'" class="forms-response-lead">{{ summary(response)?.field?.label || summary(response)?.key || '回覆內容' }}：{{ summary(response) ? valueText(summary(response)?.value) : '查看欄位答案' }}</p>
                <dl class="forms-answers"><div v-for="answer in answers(response).slice(0, responseMode === 'summary' ? 3 : undefined)" :key="answer.key"><dt>{{ answer.field?.label || answer.key }}</dt><dd v-if="answer.field?.type === 'file' && Number.isInteger(Number(answer.value)) && Number(answer.value) > 0"><a :href="`/api/v1/files/${answer.value}/download`">下載附件 #{{ answer.value }}</a></dd><dd v-else>{{ valueText(answer.value) }}</dd></div></dl>
                <button v-if="responseMode === 'summary' && answers(response).length > 3" class="forms-text-button" type="button" @click="responseMode = 'answers'">查看全部欄位答案</button>
              </article>
            </section>
          </template>
          <section v-else class="forms-panel"><h2>版本資訊</h2><p class="forms-help">發布時保存欄位快照，讓回覆依當時的題目呈現。</p><div v-if="!selected.snapshots?.length" class="forms-empty">目前沒有已發布的版本。</div><article v-for="snapshot in [...(selected.snapshots || [])].reverse()" :key="snapshot.version" class="forms-snapshot"><h3>版本 {{ snapshot.version }}</h3><p>{{ snapshot.published_at ? dateText(snapshot.published_at, true) + ' 發布' : '已發布' }} · {{ snapshot.fields?.length || 0 }} 個欄位</p><ul><li v-for="field in snapshot.fields" :key="field.key">{{ field.label }} · {{ fieldTypes.find((type) => type.value === field.type)?.label || field.type }}<span v-if="field.required"> · 必填</span></li></ul></article></section>
        </div>
        <aside class="forms-aside"><section class="forms-panel"><h3>版本資訊</h3><dl class="forms-info"><div><dt>目前版本</dt><dd>版本 {{ selected.version || 1 }}</dd></div><div><dt>狀態</dt><dd>{{ statusLabel(selected.status) }}</dd></div><div><dt>截止時間</dt><dd>{{ dateText(selected.deadline) }}</dd></div><div><dt>發布版本</dt><dd>{{ selected.snapshots?.length || 0 }} 個</dd></div></dl></section><section class="forms-panel"><h3>操作</h3><p class="forms-help">匯出授權 CSV，取得回覆與欄位快照。</p><a v-if="can('forms.export.all')" class="button" :href="`/api/v1/forms/${selected.id}/export`">匯出授權 CSV</a><button v-if="can('forms.update.all')" class="button ghost" type="button" @click="edit(selected!)">設計表單</button><NuxtLink v-if="selected.status === 'published'" class="button ghost" :to="`/app/forms/${selected.id}`">填寫表單</NuxtLink></section></aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.forms-page { --f-ink: var(--r3-ink, #193e34); --f-muted: var(--r3-muted, #64746c); --f-border: var(--r3-border, #dce3db); --f-surface: var(--r3-surface, #fff); --f-paper: var(--r3-paper, #faf8f2); color: var(--f-ink); max-width: 1312px; margin: auto; padding-bottom: 48px; }
.forms-breadcrumb { display: flex; gap: 8px; color: var(--f-muted); font-size: 13px; margin-bottom: 24px; }
.forms-breadcrumb button { border: 0; background: none; color: inherit; cursor: pointer; font: inherit; padding: 0; }
.forms-header { margin-bottom: 28px; }.forms-header h1 { font: 700 clamp(27px, 3vw, 32px)/1.55 "Noto Sans TC", sans-serif; margin: 0; }.forms-header p { color: var(--f-muted); margin: 4px 0 0; font-size: 16px; }.forms-header .forms-kicker, .forms-kicker { color: #234d3e; font-size: 13px; margin: 0 0 4px; }
.forms-section-heading, .forms-card-heading, .forms-card-actions, .forms-panel-heading, .forms-fields-heading, .forms-field-top, .forms-response header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }.forms-section-heading { align-items: center; margin-bottom: 16px; }.forms-section-heading h2 { font-size: 24px; margin: 0; }.forms-section-heading span { font-size: 13px; color: var(--f-muted); }
.forms-list, .forms-main-column, .forms-aside { display: grid; gap: 20px; min-width: 0; }.forms-list { gap: 16px; }.forms-card, .forms-panel { background: var(--f-surface); border: 1px solid var(--f-border); border-radius: 16px; padding: 24px; box-shadow: none; min-width: 0; }.forms-card h3, .forms-panel h2, .forms-panel h3, .forms-field-card h4, .forms-response h3 { font: 700 18px/1.55 "Noto Sans TC", sans-serif; margin: 0; }.forms-panel h2 { font-size: 20px; }.forms-card-heading p, .forms-response header p { color: var(--f-muted); font-size: 14px; margin: 2px 0 0; }.forms-description, .forms-help, .forms-snapshot p { color: var(--f-muted); }.forms-description { margin: 16px 0 0; }.forms-help { font-size: 14px; line-height: 1.6; margin: 4px 0 12px; }
.forms-card-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin: 22px 0; }.forms-card-meta dt, .forms-info dt, .forms-answers dt { color: var(--f-muted); font-size: 13px; }.forms-card-meta dd, .forms-info dd, .forms-answers dd { margin: 5px 0 0; overflow-wrap: anywhere; }.forms-card-meta dd { font-weight: 700; }.forms-card-actions { justify-content: flex-end; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--f-border); padding-top: 16px; }.forms-page .button { min-height: 42px; border-radius: 10px; font-size: 14px; }.forms-create { margin-top: 20px; }
.forms-mobile-pills, .forms-more-actions { display: none; }.forms-secondary-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.forms-pill { display: inline-flex; flex: none; border-radius: 999px; background: #e7eee6; color: #234d3e; padding: 6px 12px; font-size: 13px; font-weight: 500; }.forms-pill.draft { background: #fff1d1; color: #8a5e1b; }.forms-empty { background: var(--f-surface); border: 1px solid var(--f-border); border-radius: 16px; padding: 24px; color: var(--f-muted); }
.forms-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, 320px); align-items: start; gap: 24px; }.forms-designer { display: grid; gap: 16px; }.forms-designer > .forms-pill { justify-self: start; }.forms-two-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }.forms-page .field { min-width: 0; }.forms-page .field input, .forms-page .field select, .forms-page .field textarea { border-color: var(--f-border); border-radius: 10px; background: var(--f-surface); color: var(--f-ink); }
.forms-roles { border: 1px solid var(--f-border); border-radius: 10px; margin: 0; padding: 14px; display: flex; gap: 12px 18px; flex-wrap: wrap; }.forms-roles legend { font-size: 14px; font-weight: 700; padding: 0 4px; }.forms-roles .forms-help { flex-basis: 100%; }.forms-roles label, .forms-check { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }.forms-fields-heading { align-items: center; border-top: 1px solid var(--f-border); padding-top: 16px; }.forms-field-card { background: var(--f-paper); border: 1px solid var(--f-border); border-radius: 12px; padding: 16px; display: grid; gap: 14px; }.forms-field-top { align-items: center; }.forms-field-actions { display: flex; gap: 8px; flex-wrap: wrap; }.forms-field-actions button, .forms-text-button { border: 0; color: #234d3e; background: transparent; cursor: pointer; font: inherit; font-size: 13px; padding: 3px; }.forms-field-actions button:disabled { color: #a5b0a9; cursor: default; }.forms-field-actions .forms-remove { color: #9c4235; }.forms-editor-actions { display: flex; flex-wrap: wrap; gap: 10px; }.forms-info { margin: 0; display: grid; gap: 13px; }.forms-info dd { font-weight: 600; font-size: 14px; }.forms-aside .button { margin-top: 12px; width: 100%; }
.forms-tabs { display: flex; gap: 8px; margin-bottom: 20px; }.forms-tabs button, .forms-segmented button { font: inherit; cursor: pointer; border-radius: 9px; padding: 9px 16px; background: #e7eee6; border: 1px solid #c6d2c6; color: #234d3e; }.forms-tabs button.active, .forms-segmented button.active { background: #234d3e; border-color: #234d3e; color: #fff; }.forms-panel-heading { align-items: center; margin-bottom: 18px; }.forms-filters { display: grid; grid-template-columns: 1fr 180px; gap: 12px; margin-bottom: 14px; }.forms-segmented { display: flex; gap: 8px; flex-wrap: wrap; }.forms-response, .forms-snapshot { padding: 18px 0; border-top: 1px solid var(--f-border); }.forms-response:first-of-type { margin-top: 14px; }.forms-response-lead { background: var(--f-paper); border-radius: 10px; padding: 12px; overflow-wrap: anywhere; }.forms-answers { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 12px 0; }.forms-answers > div { background: var(--f-paper); border: 1px solid var(--f-border); border-radius: 10px; padding: 12px; min-width: 0; }.forms-answers dd a { color: #234d3e; text-decoration: underline; }.forms-snapshot ul { margin: 8px 0 0; padding-left: 22px; }
@media (max-width: 850px) { .forms-layout { grid-template-columns: 1fr; }.forms-aside { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) { .forms-page { padding-bottom: 24px; }.forms-breadcrumb { margin-bottom: 22px; }.forms-header { margin-bottom: 24px; }.forms-header h1 { font-size: 28px; }.forms-header > p:not(.forms-kicker), .forms-section-heading { display: none; }.forms-card, .forms-panel { padding: 20px; }.forms-card-heading > .forms-pill, .forms-card-meta, .forms-description, .forms-secondary-actions { display: none; }.forms-mobile-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }.forms-card-actions { justify-content: stretch; border-top: 0; padding-top: 16px; }.forms-card-actions .button, .forms-create { width: 100%; }.forms-more-actions { display: block; width: 100%; }.forms-more-actions > summary { cursor: pointer; color: #234d3e; font-size: 13px; text-align: center; padding: 7px; }.forms-more-actions > div { display: grid; gap: 8px; margin-top: 8px; }.forms-two-fields, .forms-filters, .forms-aside, .forms-answers { grid-template-columns: 1fr; }.forms-field-top { align-items: flex-start; }.forms-field-actions { justify-content: flex-end; }.forms-tabs button { flex: 1; } }
</style>
