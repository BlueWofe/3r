<script setup lang="ts">
definePageMeta({ layout: "app" });
const { user, refresh } = useAuth();
const name = ref(""),
  phone = ref(""),
  code = ref(""),
  newPhone = ref("");
const line = reactive<any>({ bound: false, subscribed: false });
const donations = ref<any[]>([]);
const saved = ref("");
const { error, run } = useApiError();
onMounted(async () => {
  await refresh();
  name.value = user.value?.name || "";
  phone.value = user.value?.phone || "";
  try {
    [donations.value] = await Promise.all([
      api<any>("/donations").then((x) => x.data || []),
    ]);
    Object.assign(line, await api("/integrations/line"));
  } catch (e: any) {
    error.value = e.message;
  }
});
async function update() {
  await run(() =>
    api("/auth/profile", { method: "PUT", body: { name: name.value } }),
  );
  saved.value = "個人資料已更新";
  await refresh();
}
async function otp() {
  await run(() =>
    api("/auth/otp", {
      method: "POST",
      body: { phone: newPhone.value, purpose: "change_phone" },
    }),
  );
  saved.value = "驗證碼已送往允許測試的私人信箱。";
}
async function change() {
  await run(() =>
    api("/auth/change-phone", {
      method: "POST",
      body: { phone: newPhone.value, code: code.value },
    }),
  );
  saved.value = "手機號碼已更新";
  await refresh();
}
async function lineSave() {
  await run(() => api("/integrations/line", { method: "PUT", body: line }));
  saved.value = "LINE 模擬設定已更新";
}
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">MY ACCOUNT</p>
      <h1>個人資料與奉獻</h1>
    </div>
  </div>
  <div class="grid profile-grid">
    <form class="card form" @submit.prevent="update">
      <h3>基本資料</h3>
      <label class="field">姓名<input v-model="name" required /></label
      ><label class="field">目前手機<input :value="phone" disabled /></label
      ><button class="button">更新資料</button>
    </form>
    <form class="card form" @submit.prevent="change">
      <h3>變更手機</h3>
      <label class="field">新手機<input v-model="newPhone" required /></label
      ><button type="button" class="button ghost" @click="otp">
        取得驗證碼</button
      ><label class="field">驗證碼<input v-model="code" required /></label
      ><button class="button">確認變更</button>
    </form>
    <form class="card form" @submit.prevent="lineSave">
      <h3>LINE 通知（模擬）</h3>
      <label><input v-model="line.bound" type="checkbox" /> 已綁定帳號</label
      ><label
        ><input v-model="line.subscribed" type="checkbox" /> 訂閱服務通知</label
      ><button class="button">儲存模擬設定</button>
    </form>
    <section class="card">
      <h3>我的捐款紀錄</h3>
      <NuxtLink class="button gold" to="/donate">新增模擬捐款</NuxtLink>
      <div v-if="donations.length" class="tablewrap" style="margin-top: 12px">
        <table class="table">
          <tr v-for="d in donations" :key="d.id">
            <td>{{ d.created_at }}</td>
            <td>{{ d.purpose }}</td>
            <td>{{ d.amount }}</td>
            <td>{{ d.status }}</td>
          </tr>
        </table>
      </div>
      <p v-else class="muted">尚無捐款紀錄。</p>
    </section>
  </div>
  <p v-if="saved" class="notice">{{ saved }}</p>
  <p v-if="error" class="error">{{ error }}</p>
</template>
