<script setup lang="ts">
definePageMeta({ layout: "app" });
const invitations = ref<any[]>([]),
  notifications = ref<any[]>([]);
const { can, refresh } = useAuth();
const canRespond = () =>
  can("schedule.update.own") || can("schedule.update.all");
const { error, run } = useApiError();
async function load() {
  error.value = "";
  try {
    notifications.value = (await api<any>("/notifications")).data || [];
    invitations.value = canRespond()
      ? (await api<any>("/invitations")).data || []
      : [];
  } catch (e: any) {
    error.value = e.message;
  }
}
async function respond(i: any, action: string) {
  await run(() =>
    api(`/invitations/${i.id}/respond`, { method: "POST", body: { action } }),
  );
  load();
}
async function read(n: any) {
  await run(() => api(`/notifications/${n.id}/read`, { method: "POST" }));
  load();
}
onMounted(async () => {
  await refresh();
  await load();
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">INBOX</p>
      <h1>邀請與通知</h1>
    </div>
  </div>
  <section v-if="canRespond()">
    <h2 class="serif">代課邀請</h2>
    <div v-if="!invitations.length" class="card empty">
      目前沒有待回覆的邀請。
    </div>
    <article v-for="i in invitations" :key="i.id" class="card">
      <h3>{{ i.session?.title || i.title }}</h3>
      <p class="muted">{{ i.reason }} · {{ i.session?.service_date }}</p>
      <div class="actions">
        <button class="button" @click="respond(i, 'accept')">接受</button
        ><button class="button ghost" @click="respond(i, 'decline')">
          婉拒
        </button>
      </div>
    </article>
  </section>
  <section class="section" style="padding-bottom: 0">
    <h2 class="serif">通知收件匣</h2>
    <article
      v-for="n in notifications"
      :key="n.id"
      class="card"
      style="margin: 8px 0"
    >
      <NuxtLink v-if="n.url" :to="n.url"
        ><b>{{
          n.type === "group_news" ? "新的小組消息" : n.title || n.type
        }}</b></NuxtLink
      >
      <b v-else>{{
        n.type === "group_news" ? "新的小組消息" : n.title || n.type
      }}</b>
      <p v-if="n.type !== 'group_news'" class="muted">
        {{ n.body || n.message }}
      </p>
      <button
        v-if="!n.read && !n.read_at"
        class="button ghost"
        @click="read(n)"
      >
        標為已讀
      </button>
    </article>
    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>
