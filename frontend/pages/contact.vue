<script setup lang="ts">
const sent = ref(false);
const { data: contact } = await useAsyncData("contact-page", () =>
  api<any>("/public/contact").catch(() => ({ data: null })),
);
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">CONTACT</div>
      <h1>與我們聯絡</h1>
    </div>
  </div>
  <section class="section">
    <div class="container grid" style="grid-template-columns: 1fr 1fr">
      <div>
        <h2>我們願意聽見你</h2>
        <p class="muted">
          若您想認識服務、加入志工或需要轉介資訊，歡迎留下訊息。此表單為介面示範，並不會送出真實資料。
        </p>
        <p class="muted">
          {{ contact?.data?.association_name || "中華復甦更新發展協會"
          }}<br />服務專線 {{ contact?.data?.contact_phone || "02-0000-0000"
          }}<br />{{ contact?.data?.contact_email || "service@example.test"
          }}<br />{{ contact?.data?.address || "聯絡地址尚待設定" }}
        </p>
      </div>
      <form class="card form" @submit.prevent="sent = true">
        <label class="field">姓名<input required /></label
        ><label class="field">電子郵件<input type="email" required /></label
        ><label class="field">想說的話<textarea required></textarea></label
        ><button class="button">送出訊息</button>
        <p v-if="sent" class="notice">
          已完成示範送出；正式服務啟用後會由同工回覆。
        </p>
      </form>
    </div>
  </section>
</template>
