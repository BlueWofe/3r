<script setup lang="ts">
const tab = ref<"login" | "register" | "reset">("login");
const phone = ref(""),
  name = ref(""),
  password = ref(""),
  confirm = ref(""),
  code = ref(""),
  otpRequested = ref(false);
const { login } = useAuth();
const { error, run } = useApiError();
async function requestOtp() {
  await run(() =>
    api("/auth/otp", {
      method: "POST",
      body: {
        phone: phone.value,
        purpose: tab.value === "reset" ? "reset" : "register",
      },
    }),
  );
  otpRequested.value = true;
}
async function submit() {
  if (tab.value === "login") {
    await run(() => login(phone.value, password.value));
    return navigateTo("/app");
  }
  const path =
    tab.value === "register" ? "/auth/register" : "/auth/reset-password";
  await run(() =>
    api(path, {
      method: "POST",
      body:
        tab.value === "register"
          ? {
              phone: phone.value,
              name: name.value,
              password: password.value,
              password_confirmation: confirm.value,
              code: code.value,
            }
          : {
              phone: phone.value,
              code: code.value,
              password: password.value,
              password_confirmation: confirm.value,
            },
    }),
  );
  tab.value = "login";
  error.value = "";
}
</script>
<template>
  <main class="auth">
    <section class="authbox">
      <NuxtLink class="brand" to="/"
        ><span class="seal">✦</span>中華復甦更新發展協會</NuxtLink
      >
      <h1 class="serif">會員入口</h1>
      <div class="tabs">
        <button :class="{ selected: tab === 'login' }" @click="tab = 'login'">
          登入</button
        ><button
          :class="{ selected: tab === 'register' }"
          @click="tab = 'register'"
        >
          註冊</button
        ><button :class="{ selected: tab === 'reset' }" @click="tab = 'reset'">
          重設密碼
        </button>
      </div>
      <form class="form" @submit.prevent="submit">
        <label v-if="tab === 'register'" class="field"
          >姓名<input v-model="name" required /></label
        ><label class="field"
          >手機號碼<input v-model="phone" inputmode="tel" required /></label
        ><label v-if="tab !== 'login'" class="field"
          >驗證碼
          <span
            ><input v-model="code" required style="width: 60%" /><button
              type="button"
              class="button ghost"
              style="margin-left: 6px"
              @click="requestOtp"
            >
              取得驗證碼
            </button></span
          ><small v-if="otpRequested" class="muted"
            >驗證碼已發送至允許測試的私人信箱；系統不會在畫面或 API
            顯示驗證碼。</small
          ></label
        ><label class="field"
          >密碼<input
            v-model="password"
            type="password"
            minlength="8"
            required /></label
        ><label v-if="tab !== 'login'" class="field"
          >確認密碼<input v-model="confirm" type="password" required /></label
        ><button class="button">
          {{ tab === "login" ? "登入" : "確認送出" }}
        </button>
        <p v-if="error" class="error">{{ error }}</p>
      </form>
    </section>
  </main>
</template>
