<script setup lang="ts">
definePageMeta({ layout: "app" });

type Category = "member" | "volunteer" | "admin";
type RoleOption = { id: number; name: string };
type LoginRecord = {
  id: number;
  user_id: number | null;
  name: string | null;
  result: "success" | "failure";
  occurred_at: string;
  ip_address: string | null;
  user_agent: string | null;
  roles: { id: number; name: string; slug: string }[];
  categories: string[];
};
type LoginRecordResponse = {
  data: LoginRecord[];
  meta: { total: number; current_page: number; per_page: number; last_page: number };
  role_options: RoleOption[];
};

const tabs: { id: Category; label: string }[] = [
  { id: "member", label: "一般使用者" },
  { id: "volunteer", label: "志工使用者" },
  { id: "admin", label: "後台管理者" },
];
const category = ref<Category>("member"), roleId = ref(""), from = ref(""), to = ref("");
const rows = ref<LoginRecord[]>([]), roleOptions = ref<RoleOption[]>([]);
const meta = reactive({ total: 0, current_page: 1, per_page: 20, last_page: 1 });
const loading = ref(false), error = ref("");
let requestId = 0;

function dateTime(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "時間未提供" : new Intl.DateTimeFormat("zh-TW", {
    timeZone: "Asia/Taipei", dateStyle: "medium", timeStyle: "medium",
  }).format(date);
}
function roleLabel(row: LoginRecord) {
  return row.roles.length ? row.roles.map((role) => role.name).join("、") : "未辨識角色";
}
function resultLabel(result: LoginRecord["result"]) { return result === "success" ? "登入成功" : "登入失敗"; }

async function load(page = meta.current_page) {
  const sequence = ++requestId;
  loading.value = true; error.value = "";
  const params = new URLSearchParams({ category: category.value, page: String(Math.max(1, page)), per_page: "20" });
  if (roleId.value) params.set("role_id", roleId.value);
  if (from.value) params.set("from", from.value);
  if (to.value) params.set("to", to.value);
  try {
    const result = await api<LoginRecordResponse>(`/login-records?${params}`);
    if (sequence !== requestId) return;
    rows.value = result.data || [];
    roleOptions.value = result.role_options || [];
    Object.assign(meta, {
      total: Number(result.meta?.total || 0),
      current_page: Math.max(1, Number(result.meta?.current_page || 1)),
      per_page: Number(result.meta?.per_page || 20),
      last_page: Math.max(1, Number(result.meta?.last_page || 1)),
    });
  } catch (e: any) {
    if (sequence === requestId) { rows.value = []; error.value = e.message || "目前無法載入登入記錄。"; }
  } finally { if (sequence === requestId) loading.value = false; }
}
function selectCategory(next: Category) {
  if (category.value === next) return;
  category.value = next; roleId.value = ""; meta.current_page = 1; void load(1);
}
function applyFilters() { meta.current_page = 1; void load(1); }
function clearFilters() { roleId.value = ""; from.value = ""; to.value = ""; applyFilters(); }
function go(page: number) {
  const target = Math.min(Math.max(1, page), meta.last_page);
  if (target === meta.current_page || loading.value) return;
  void load(target);
}
onMounted(() => load(1));
</script>

<template>
  <div class="workhead">
    <div><p class="eyebrow">ACCESS HISTORY</p><h1>登入記錄</h1><p class="muted">查看各類帳號的登入結果與裝置摘要。本頁僅供查閱。</p></div>
  </div>
  <nav class="record-tabs" role="tablist" aria-label="使用者分類">
    <button v-for="tab in tabs" :key="tab.id" type="button" role="tab" :aria-selected="category === tab.id" :class="{ selected: category === tab.id }" @click="selectCategory(tab.id)">{{ tab.label }}</button>
  </nav>
  <form class="record-filters" @submit.prevent="applyFilters">
    <label class="field">角色<select v-model="roleId" @change="applyFilters"><option value="">全部角色</option><option v-for="role in roleOptions" :key="role.id" :value="String(role.id)">{{ role.name }}</option></select></label>
    <label class="field">開始日期<input v-model="from" type="date" @change="applyFilters" /></label>
    <label class="field">結束日期<input v-model="to" type="date" @change="applyFilters" /></label>
    <div class="filter-actions"><button class="button" type="submit" :disabled="loading">套用篩選</button><button class="button ghost" type="button" :disabled="loading || (!roleId && !from && !to)" @click="clearFilters">清除篩選</button></div>
  </form>

  <p v-if="loading" class="empty" role="status">載入登入記錄中…</p>
  <p v-else-if="error" class="error" role="alert">{{ error }}</p>
  <div v-else-if="!rows.length" class="card empty">這個分類目前沒有符合條件的登入記錄。</div>
  <template v-else>
    <div class="desktop-records tablewrap">
      <table class="table"><thead><tr><th>使用者</th><th>角色</th><th>時間</th><th>結果</th><th>IP 位址</th><th>裝置</th></tr></thead><tbody>
        <tr v-for="row in rows" :key="row.id" :data-login-record-id="row.id">
          <td>{{ row.name || "未知使用者" }}</td><td>{{ roleLabel(row) }}</td><td>{{ dateTime(row.occurred_at) }}</td>
          <td><span :class="['login-result', row.result]">{{ resultLabel(row.result) }}</span></td><td>{{ row.ip_address || "未提供" }}</td>
          <td><details class="agent"><summary>查看裝置資訊</summary><p>{{ row.user_agent || "未提供裝置資訊" }}</p></details></td>
        </tr>
      </tbody></table>
    </div>
    <div class="mobile-records">
      <article v-for="row in rows" :key="row.id" class="card record-card" :data-login-record-id="row.id">
        <div class="record-title"><h2>{{ row.name || "未知使用者" }}</h2><span :class="['login-result', row.result]">{{ resultLabel(row.result) }}</span></div>
        <dl><div><dt>角色</dt><dd>{{ roleLabel(row) }}</dd></div><div><dt>時間</dt><dd>{{ dateTime(row.occurred_at) }}</dd></div><div><dt>IP 位址</dt><dd>{{ row.ip_address || "未提供" }}</dd></div></dl>
        <details class="agent"><summary>查看裝置資訊</summary><p>{{ row.user_agent || "未提供裝置資訊" }}</p></details>
      </article>
    </div>
    <nav class="pagination" aria-label="登入記錄分頁">
      <button class="button ghost" type="button" :disabled="loading || meta.current_page <= 1" @click="go(meta.current_page - 1)">上一頁</button>
      <span>第 {{ meta.current_page }}／{{ meta.last_page }} 頁，共 {{ meta.total }} 筆</span>
      <button class="button ghost" type="button" :disabled="loading || meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">下一頁</button>
    </nav>
  </template>
</template>

<style scoped>
.record-tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 20px; }
.record-tabs button { min-height: 44px; border: 1px solid var(--line); border-radius: 12px; background: var(--paper); color: var(--ink); font: 700 14px inherit; cursor: pointer; }
.record-tabs button.selected { border-color: var(--pine); background: var(--pine); color: #fff; }
.record-filters { display: grid; grid-template-columns: minmax(180px, 1fr) 180px 180px auto; gap: 12px; align-items: end; margin-bottom: 20px; }
.filter-actions { display: flex; gap: 8px; padding-bottom: 1px; }
.login-result { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 13px; font-weight: 700; white-space: nowrap; }
.login-result.success { background: #dceee5; color: #185b3d; } .login-result.failure { background: #f7dfda; color: #8b3025; }
.agent summary { color: var(--pine); cursor: pointer; font-weight: 700; } .agent p { max-width: 420px; margin: 8px 0 0; overflow-wrap: anywhere; color: var(--muted); }
.mobile-records { display: none; } .pagination { display: flex; justify-content: center; align-items: center; gap: 16px; margin-top: 20px; text-align: center; }
@media (max-width: 760px) {
  .record-tabs { gap: 5px; } .record-tabs button { padding: 6px; font-size: 13px; }
  .record-filters { grid-template-columns: 1fr 1fr; } .record-filters .field:first-child, .filter-actions { grid-column: 1 / -1; }
  .filter-actions .button { flex: 1; } .desktop-records { display: none; } .mobile-records { display: grid; gap: 12px; }
  .record-card { padding: 18px; } .record-title { display: flex; align-items: start; justify-content: space-between; gap: 12px; } .record-title h2 { margin: 0; font-size: 18px; }
  .record-card dl { margin: 14px 0; } .record-card dl div { display: grid; grid-template-columns: 72px 1fr; gap: 8px; margin: 7px 0; } .record-card dt { font-weight: 700; } .record-card dd { margin: 0; overflow-wrap: anywhere; }
  .pagination { gap: 8px; } .pagination .button { padding-inline: 12px; }
}
</style>
