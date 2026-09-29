<script setup lang="ts">
const categories = [
  "監所探訪與代禱",
  "更生安置與職訓",
  "食品採購與禮盒",
  "志工加入",
  "奉獻與收據諮詢",
  "其他諮詢",
];
const { data: contact } = await useAsyncData("contact-page", () =>
  api<any>("/public/contact").catch(() => ({ data: null })),
);
const form = reactive({
  name: "",
  phone: "",
  email: "",
  category: categories[0],
  message: "",
  website: "",
  submission_token: "",
});
const pending = ref(false),
  success = ref(""),
  error = ref("");
function token() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  b[6] = ((b[6] ?? 0) & 15) | 64;
  b[8] = ((b[8] ?? 0) & 63) | 128;
  const h = [...b].map((x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
function reset() {
  Object.assign(form, {
    name: "",
    phone: "",
    email: "",
    category: categories[0],
    message: "",
    website: "",
    submission_token: token(),
  });
}
async function submit() {
  if (pending.value) return;
  pending.value = true;
  error.value = "";
  success.value = "";
  try {
    const result = await api<any>("/public/contact", {
      method: "POST",
      body: { ...form },
    });
    success.value = result.message || "已收到您的訊息";
    reset();
  } catch (e: any) {
    error.value = e.message || "送出失敗，請稍後再試。";
  } finally {
    pending.value = false;
  }
}
reset();
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">CONTACT</div>
      <h1>與我們聯絡</h1>
    </div>
  </div>
  <section class="section">
    <div class="container grid responsive-two contact-layout">
      <div>
        <h2>我們願意聽見你</h2>
        <p class="muted">
          若您想認識服務、加入志工或需要轉介資訊，歡迎留下訊息。
        </p>
        <div class="contact-facts">
          <p>
            {{ contact?.data?.association_name || "中華復甦更新發展協會"
            }}<br />服務專線 {{ contact?.data?.contact_phone || "02-0000-0000"
            }}<br />{{ contact?.data?.contact_email || "service@example.test"
            }}<br />{{ contact?.data?.address || "聯絡地址尚待設定" }}
          </p>
        </div>
      </div>
      <form class="card form" @submit.prevent="submit">
        <h3>留下訊息</h3>
        <label class="field"
          >姓名<input
            v-model.trim="form.name"
            maxlength="100"
            required /></label
        ><label class="field"
          >電話<input
            v-model.trim="form.phone"
            maxlength="50"
            required /></label
        ><label class="field"
          >電子郵件<input
            v-model.trim="form.email"
            type="email"
            maxlength="254" /></label
        ><label class="field"
          >諮詢分類<select v-model="form.category">
            <option v-for="item in categories" :key="item">{{ item }}</option>
          </select></label
        ><label class="field"
          >想說的話<textarea
            v-model.trim="form.message"
            maxlength="10000"
            required
          /></label
        ><label class="visually-hidden"
          >網站<input
            v-model="form.website"
            tabindex="-1"
            autocomplete="off" /></label
        ><button class="button" :disabled="pending">
          {{ pending ? "送出中…" : "送出訊息" }}
        </button>
        <p v-if="success" class="notice">{{ success }}</p>
        <p v-if="error" class="error">{{ error }}</p>
      </form>
    </div>
  </section>
</template>
