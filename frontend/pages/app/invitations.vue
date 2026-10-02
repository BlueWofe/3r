<script setup lang="ts">
definePageMeta({ layout: "app" });
const route = useRoute();
const invitations = ref<any[]>([]), selectedCategory = ref("all"), responding = ref<number | null>(null), allReadPending = ref(false);
const { can, refresh } = useAuth();
const canRespond = () => can("schedule.update.own");
const { error: actionError, run } = useApiError();
const { items: notifications, unreadCount, loading, error: notificationError, refreshNotifications, markNotificationRead, markAllNotificationsRead } = useNotifications();
const categories = [
  { id: "all", label: "全部" }, { id: "product", label: "產品通知" },
  { id: "course", label: "課程通知" }, { id: "message", label: "小組消息" },
  { id: "contact", label: "其他聯絡" },
];
const categoryUnreadCounts = computed(() => {
  const counts: Record<string, number> = Object.fromEntries(categories.map(category => [category.id, 0]));
  for (const item of notifications.value) {
    if (item.read || item.read_at) continue;
    counts.all!++;
    if (item.category && item.category !== "all" && item.category in counts) counts[item.category]!++;
  }
  return counts;
});
const filteredNotifications = computed(() => selectedCategory.value === "all" ? notifications.value : notifications.value.filter((item) => item.category === selectedCategory.value));
const focusedInvitationId = computed(() => Number(route.query.invitation_id) || null);
const pendingInvitations = computed(() => invitations.value.filter((item) => !item.status || item.status === "pending"));
const invitationHistory = computed(() => invitations.value.filter((item) => item.status && item.status !== "pending"));
const invitationStatus: Record<string, string> = { accepted: "已接受", declined: "已婉拒", cancelled: "已取消" };
function notificationTitle(item: any) { return item.type === "group_news" ? "新的小組消息" : item.title || "站內通知"; }
function createdLabel(value?: string) {
  if (!value) return ""; const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : new Intl.DateTimeFormat("zh-TW", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Taipei" }).format(date);
}
async function load() {
  await refreshNotifications();
  invitations.value = canRespond() ? (await api<any>("/invitations")).data || [] : [];
  await nextTick();
  if (focusedInvitationId.value) document.getElementById(`invitation-${focusedInvitationId.value}`)?.scrollIntoView({ block: "center" });
}
async function respond(invitation: any, action: "accept" | "decline") {
  responding.value = invitation.id;
  try { await run(() => api(`/invitations/${invitation.id}/respond`, { method: "POST", body: { action } })); await load(); }
  finally { responding.value = null; }
}
async function openNotification(item: any) {
  await run(async () => { const updated = await markNotificationRead(item); const destination = notificationDestination(updated.url); if (destination) await navigateTo(destination); });
}
async function readAll() { allReadPending.value = true; try { await run(markAllNotificationsRead); } finally { allReadPending.value = false; } }
onMounted(async () => { await refresh(); await load(); });
</script>

<template>
  <div class="workhead inbox-head">
    <div><p class="eyebrow">INBOX</p><h1>通知收件匣</h1><p class="muted">{{ unreadCount ? `${unreadCount} 則未讀通知` : "目前沒有未讀通知" }}</p></div>
    <button class="button ghost" type="button" :disabled="allReadPending || !unreadCount" @click="readAll">{{ allReadPending ? "處理中…" : "全部已讀" }}</button>
  </div>
  <section v-if="canRespond()" aria-labelledby="invitation-title">
    <h2 id="invitation-title" class="serif">代課邀請</h2>
    <div v-if="!pendingInvitations.length" class="card empty">目前沒有待回覆的邀請。</div>
    <article v-for="invitation in pendingInvitations" :id="`invitation-${invitation.id}`" :key="invitation.id" class="card invitation-card" :class="{ focused: focusedInvitationId === invitation.id }">
      <span v-if="focusedInvitationId === invitation.id" class="status scheduled">這則邀請</span>
      <h3>{{ invitation.session?.title || invitation.title || "代課邀請" }}</h3>
      <p class="muted">{{ invitation.reason || "邀請您協助代課" }}<template v-if="invitation.session?.service_date"> · {{ invitation.session.service_date }}</template></p>
      <div class="actions"><button class="button" :disabled="responding !== null" @click="respond(invitation, 'accept')">接受</button><button class="button ghost" :disabled="responding !== null" @click="respond(invitation, 'decline')">婉拒</button></div>
    </article>
    <details v-if="invitationHistory.length" class="invitation-history" :open="invitationHistory.some((item) => item.id === focusedInvitationId)"><summary>查看過往邀請（{{ invitationHistory.length }}）</summary>
      <article v-for="invitation in invitationHistory" :id="`invitation-${invitation.id}`" :key="invitation.id" class="card invitation-card" :class="{ focused: focusedInvitationId === invitation.id }">
        <h3>{{ invitation.session?.title || invitation.title || "代課邀請" }}</h3><p class="muted">{{ invitationStatus[invitation.status] || invitation.status }}<template v-if="invitation.session?.service_date"> · {{ invitation.session.service_date }}</template></p>
      </article>
    </details>
  </section>
  <section class="section inbox-section" aria-labelledby="notification-title">
    <h2 id="notification-title" class="serif">站內通知</h2>
    <nav class="inbox-tabs" role="tablist" aria-label="通知分類">
      <button v-for="category in categories" :key="category.id" role="tab" type="button" :aria-selected="selectedCategory === category.id" :aria-label="categoryUnreadCounts[category.id] ? `${category.label}，${categoryUnreadCounts[category.id]}則未讀` : category.label" :class="{ selected: selectedCategory === category.id }" @click="selectedCategory = category.id">
        <span>{{ category.label }}</span><span v-if="categoryUnreadCounts[category.id]" class="tab-unread-count" :data-testid="`notification-tab-count-${category.id}`" aria-hidden="true">{{ categoryUnreadCounts[category.id] }}</span>
      </button>
    </nav>
    <p v-if="loading" class="muted" role="status">載入通知中…</p>
    <div v-else-if="!filteredNotifications.length" class="card empty">這個分類目前沒有通知。</div>
    <article v-for="item in filteredNotifications" :key="item.id" class="card inbox-item" :class="{ unread: !item.read && !item.read_at }" :data-notification-id="item.id" :data-category="item.category || ''" :data-unread="!item.read && !item.read_at">
      <button type="button" class="inbox-item-button" @click="openNotification(item)">
        <span class="inbox-item-title"><b>{{ notificationTitle(item) }}</b><span v-if="!item.read && !item.read_at" class="unread-dot">未讀</span></span>
        <span v-if="item.type !== 'group_news' && (item.body || item.message)" class="muted">{{ item.body || item.message }}</span>
        <small v-if="createdLabel(item.created_at)" class="muted">{{ createdLabel(item.created_at) }}</small>
        <small v-if="notificationDestination(item.url)" class="open-hint">開啟相關內容</small>
      </button>
    </article>
    <p v-if="notificationError || actionError" class="error" role="alert">{{ actionError || notificationError }}</p>
  </section>
</template>

<style scoped>
.inbox-head { align-items: end; } .inbox-section { padding-bottom: 0; }
.inbox-tabs { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin: 12px 0 16px; }
.inbox-tabs button { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 4px; min-width: 0; min-height: 44px; padding: 7px 8px; border: 1px solid var(--line); border-radius: 999px; background: var(--paper); color: var(--ink); font: 600 13px inherit; cursor: pointer; white-space: nowrap; }
.inbox-tabs button.selected { border-color: var(--pine); background: var(--pine); color: white; }
.tab-unread-count { min-width: 20px; padding: 2px 5px; border-radius: 99px; background: #f1dfb8; color: #684b16; font-size: 12px; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; max-width: 100%; }
.invitation-card, .inbox-item { margin: 8px 0; } .invitation-card.focused { outline: 3px solid var(--gold); outline-offset: 2px; }
.invitation-history { margin-top: 12px; } .invitation-history summary { cursor: pointer; font-weight: 700; }
.inbox-item { padding: 0; overflow: hidden; } .inbox-item.unread { border-left: 5px solid var(--gold); }
.inbox-item-button { display: grid; gap: 5px; width: 100%; padding: 16px; border: 0; background: transparent; color: inherit; text-align: left; font: inherit; cursor: pointer; }
.inbox-item-button:hover { background: #f7f2e8; } .inbox-item-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.unread-dot { flex: none; padding: 2px 8px; border-radius: 99px; background: #f1dfb8; color: #684b16; font-size: 12px; font-weight: 700; }
.open-hint { color: var(--pine); font-weight: 700; }
@media (max-width: 620px) { .inbox-tabs { grid-template-columns: repeat(3, minmax(0, 1fr)); } .inbox-tabs button { white-space: normal; line-height: 1.25; } .inbox-head { align-items: start; } }
</style>
