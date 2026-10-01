<script setup lang="ts">
definePageMeta({ layout: false });
const { user, refresh, logout } = useAuth();
const { workspacePath } = useWorkspaceNavigation();
const form = reactive({ current_password: "", password: "", password_confirmation: "" });
const pending = ref(false), error = ref("");
async function submit() {
  if (pending.value) return;
  error.value = "";
  if (form.password === form.current_password) { error.value = "新密碼不能與初始密碼相同。"; return; }
  if (form.password !== form.password_confirmation) { error.value = "兩次輸入的新密碼不一致。"; return; }
  pending.value = true;
  try {
    await api("/auth/password", { method: "PUT", body: { ...form } });
    const updated = await refresh();
    if (!updated || updated.must_change_password) throw new Error("密碼已送出，但帳號狀態尚未更新，請再試一次。");
    await navigateTo(workspacePath.value);
  } catch (e: any) { error.value = e.message || "目前無法更新密碼。"; }
  finally { pending.value = false; }
}
</script>

<template>
  <main class="password-page">
    <section class="password-card">
      <NuxtLink class="password-brand" to="/" aria-label="回到協會官網">
        <img src="/images/association-backend-logo.png" alt="中華復甦更新發展協會標誌" />
        <span>中華復甦更新發展協會</span>
      </NuxtLink>
      <p class="eyebrow">ACCOUNT SECURITY</p>
      <h1 class="serif">設定您的新密碼</h1>
      <p class="muted">{{ user?.name }}，管理員提供的初始密碼只能用於第一次登入。請先換成只有您知道的新密碼，再進入工作台。</p>
      <form class="form" @submit.prevent="submit">
        <fieldset :disabled="pending">
          <label class="field">目前密碼<input v-model="form.current_password" type="password" autocomplete="current-password" required autofocus /></label>
          <label class="field">新密碼<input v-model="form.password" type="password" autocomplete="new-password" minlength="10" required /></label>
          <small class="muted">至少 10 個字元，且不能與初始密碼相同。</small>
          <label class="field">再次輸入新密碼<input v-model="form.password_confirmation" type="password" autocomplete="new-password" minlength="10" required /></label>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <button class="button" type="submit">{{ pending ? "更新中…" : "更新密碼並進入工作台" }}</button>
        </fieldset>
      </form>
      <button class="logout-button" type="button" :disabled="pending" @click="logout">登出並返回官網</button>
    </section>
  </main>
</template>

<style scoped>
.password-page { min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: radial-gradient(circle at top right, rgba(198,157,77,.2), transparent 35%), var(--cream); }
.password-card { width: min(520px, 100%); padding: clamp(24px, 6vw, 44px); border: 1px solid var(--line); border-radius: 20px; background: var(--paper); box-shadow: var(--shadow); }
.password-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; font: 700 17px/1.4 "Noto Serif TC", serif; }
.password-brand img { width: 72px; height: 72px; object-fit: cover; border-radius: 12px; }
.password-card h1 { margin: 4px 0 10px; }
.password-card fieldset { display: grid; gap: 14px; min-width: 0; margin: 24px 0 0; padding: 0; border: 0; }
.logout-button { display: block; margin: 20px auto 0; border: 0; background: transparent; color: var(--muted); text-decoration: underline; font: inherit; cursor: pointer; }
@media (max-width: 480px) { .password-page { align-items: start; padding: 12px; } .password-card { padding: 24px 20px; border-radius: 14px; } .password-brand { margin-bottom: 20px; } }
</style>
