<script setup lang="ts">
definePageMeta({ layout: "app" });
const { user, refresh, can } = useAuth();
const tabs = [
  { id: "profile", label: "個人資料" },
  { id: "password", label: "更改密碼" },
  { id: "donations", label: "我的奉獻" },
  { id: "notifications", label: "消息通知" },
];
const selected = ref("profile");
const name = ref(""), phone = ref(""), code = ref(""), newPhone = ref("");
const password = reactive({ current_password: "", password: "", password_confirmation: "" });
const line = reactive({ bound: false, subscribed: false });
const donations = ref<any[]>([]);
const saved = ref(""), error = ref(""), pending = ref(false), loading = ref(true);
const donationError = ref(""), lineError = ref("");
const statusLabels: Record<string, string> = { pending: "處理中", success: "成功", failed: "失敗", cancelled: "已取消" };
function dateLabel(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : new Intl.DateTimeFormat("zh-TW", { timeZone: "Asia/Taipei", dateStyle: "medium" }).format(date);
}
async function load() {
  await refresh();
  name.value = user.value?.name || "";
  phone.value = user.value?.phone || "";
  await Promise.all([
    (async () => {
      if (!can("donations.read.own") && !can("donations.read.all")) return;
      try {
        const result = await api<any>("/donations?own=1");
        donations.value = result.data || [];
      } catch (e: any) { donationError.value = e.message; }
    })(),
    (async () => {
      try { Object.assign(line, await api("/integrations/line")); }
      catch (e: any) { lineError.value = e.message; }
    })(),
  ]);
  loading.value = false;
}
async function perform(action: () => Promise<void>, message: string) {
  if (pending.value) return;
  pending.value = true;
  saved.value = "";
  error.value = "";
  try {
    await action();
    saved.value = message;
  } catch (e: any) { error.value = e.message; }
  finally { pending.value = false; }
}
async function update() {
  await perform(async () => {
    await api("/auth/profile", { method: "PUT", body: { name: name.value } });
    await refresh();
    name.value = user.value?.name || "";
  }, "個人資料已更新");
}
async function otp() {
  await perform(async () => {
    await api("/auth/otp", { method: "POST", body: { phone: newPhone.value, purpose: "change_phone" } });
  }, "模擬驗證碼已寫入伺服器測試信箱，請由測試管理員取得。");
}
async function change() {
  await perform(async () => {
    await api("/auth/change-phone", { method: "POST", body: { phone: newPhone.value, code: code.value } });
    await refresh();
    phone.value = user.value?.phone || "";
    newPhone.value = "";
    code.value = "";
  }, "手機號碼已更新");
}
async function changePassword() {
  await perform(async () => {
    if (password.password !== password.password_confirmation) throw new Error("兩次輸入的新密碼不一致。");
    await api("/auth/password", { method: "PUT", body: { ...password } });
    password.current_password = "";
    password.password = "";
    password.password_confirmation = "";
  }, "密碼已更新");
}
async function lineSave() {
  await perform(async () => {
    await api("/integrations/line", { method: "PUT", body: { ...line } });
    lineError.value = "";
  }, "LINE 模擬設定已更新");
}
watch(selected, () => { saved.value = ""; error.value = ""; });
onMounted(load);
</script>
<template>
  <div class="workhead"><div><p class="eyebrow">MY ACCOUNT</p><h1>我的帳戶</h1><p class="muted">管理個人資料、密碼與通知，查看自己的奉獻紀錄。</p></div></div>
  <nav class="tabs account-tabs" aria-label="帳戶功能">
    <button v-for="tab in tabs" :key="tab.id" type="button" :class="{ selected: selected === tab.id }" :aria-current="selected === tab.id ? 'page' : undefined" @click="selected = tab.id">{{ tab.label }}</button>
  </nav>
  <p v-if="loading" class="muted" role="status">載入帳戶資料中…</p>
  <div v-if="selected === 'profile'" class="grid profile-grid">
    <form class="card form" @submit.prevent="update">
      <h2>基本資料</h2>
      <label class="field">姓名<input v-model="name" autocomplete="name" required maxlength="100" /></label>
      <label class="field">目前手機<input :value="phone" disabled /></label>
      <button class="button" :disabled="pending || loading">更新資料</button>
    </form>
    <form class="card form" @submit.prevent="change">
      <h2>變更手機</h2>
      <label class="field">新手機<input v-model="newPhone" type="tel" autocomplete="tel" inputmode="numeric" pattern="09[0-9]{8}" placeholder="09 開頭的 10 碼手機號碼" required /></label>
      <button type="button" class="button ghost" :disabled="pending || !/^09\d{8}$/.test(newPhone)" @click="otp">取得驗證碼</button>
      <label class="field">驗證碼<input v-model="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required /></label>
      <button class="button" :disabled="pending || loading">確認變更</button>
    </form>
  </div>
  <form v-else-if="selected === 'password'" class="card form account-panel" @submit.prevent="changePassword">
    <h2>更改密碼</h2>
    <label class="field">目前密碼<input v-model="password.current_password" type="password" autocomplete="current-password" required /></label>
    <label class="field">新密碼<input v-model="password.password" type="password" autocomplete="new-password" minlength="10" required /></label>
    <p class="muted">新密碼至少 10 個字元。</p>
    <label class="field">再次輸入新密碼<input v-model="password.password_confirmation" type="password" autocomplete="new-password" minlength="10" required /></label>
    <button class="button" :disabled="pending || loading">{{ pending ? "處理中…" : "更新密碼" }}</button>
  </form>
  <section v-else-if="selected === 'donations'" class="card">
    <div class="workhead"><h2>我的奉獻紀錄</h2><NuxtLink class="button gold" to="/donate">前往奉獻</NuxtLink></div>
    <p v-if="donationError" class="error" role="alert">{{ donationError }}</p>
    <div v-if="donations.length" class="tablewrap">
      <table class="table"><thead><tr><th>日期</th><th>用途</th><th>金額</th><th>狀態</th></tr></thead><tbody>
        <tr v-for="d in donations" :key="d.id"><td data-label="日期">{{ dateLabel(d.created_at) }}</td><td data-label="用途">{{ d.purpose }}</td><td data-label="金額">NT$ {{ Number(d.amount).toLocaleString('zh-TW') }}</td><td data-label="狀態">{{ statusLabels[d.status] || d.status }}</td></tr>
      </tbody></table>
    </div>
    <p v-else-if="!loading && !donationError" class="muted">尚無奉獻紀錄。</p>
  </section>
  <form v-else class="card form account-panel" @submit.prevent="lineSave">
    <h2>消息通知</h2>
    <NuxtLink class="button ghost" to="/app/invitations">前往通知收件匣</NuxtLink>
    <h3>LINE 通知（模擬）</h3><p class="muted">目前為模擬綁定與訂閱設定，不會對外發送訊息。</p>
    <p v-if="lineError" class="error" role="alert">{{ lineError }}</p>
    <label><input v-model="line.bound" type="checkbox" /> 已綁定帳號</label>
    <label><input v-model="line.subscribed" type="checkbox" /> 訂閱最新消息</label>
    <button class="button" :disabled="pending || loading || !!lineError">儲存模擬設定</button>
  </form>
  <p v-if="saved" class="notice" role="status">{{ saved }}</p><p v-if="error" class="error" role="alert">{{ error }}</p>
</template>
<style scoped>
.account-tabs { overflow-x: auto; }
.account-tabs button { flex: 0 0 auto; min-height: 44px; }
.account-panel { max-width: 620px; }
</style>
