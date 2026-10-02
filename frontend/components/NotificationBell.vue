<script setup lang="ts">
const open = ref(false), following = ref(false), readingAll = ref(false), readMessage = ref("");
const { items, unreadCount: unread, loading, error, refreshNotifications: load, markNotificationRead, markAllNotificationsRead } = useNotifications();
const route = useRoute();
async function readAll() {
  if (readingAll.value || following.value || !unread.value) return;
  readingAll.value = true; readMessage.value = ""; error.value = "";
  try { await markAllNotificationsRead(); readMessage.value = "已全部標示為已讀"; }
  catch (e: any) { error.value = e.message || "暫時無法標示已讀，請稍後再試。"; }
  finally { readingAll.value = false; }
}
async function follow(item: any) {
    if (following.value || readingAll.value) return;
  following.value = true;
  try {
    const updated = await markNotificationRead(item);
    open.value = false;
    const destination = notificationDestination(updated.url);
    if (destination) await navigateTo(destination);
  } catch (e: any) {
    error.value = e.message || "此通知目前無法開啟，請重新整理。";
  } finally {
    following.value = false;
  }
}
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  void load();
  timer = setInterval(() => { if (document.visibilityState === "visible") void load(); }, 30000);
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
watch(() => route.fullPath, () => { open.value = false; void load(); });
</script>
<template>
  <div class="notification-bell" @keydown.esc.stop="open = false">
    <button
      type="button"
      class="bell"
      aria-label="通知收件匣"
      :aria-expanded="open"
      @click="
        open = !open;
        open && load();
      "
    >
      <NavIcon name="bell" /><span
        v-if="unread"
        class="badge"
        :aria-label="`${unread} 則未讀通知`"
        >{{ unread > 99 ? "99+" : unread }}</span
      >
    </button>
    <section v-if="open" class="notification-popover" aria-label="通知收件匣">
      <div class="popover-head">
        <strong>通知</strong
        ><NuxtLink to="/app/invitations" @click="open = false"
          >全部通知</NuxtLink
        >
      </div>
      <button class="button ghost read-all" type="button" data-testid="bell-read-all" :disabled="readingAll || following || loading || !unread" @click="readAll">{{ readingAll ? "處理中…" : "全部已讀" }}</button>
      <p v-if="readMessage" class="muted" role="status">{{ readMessage }}</p>
      <p v-if="loading" class="muted">載入中…</p>
      <p v-else-if="error" class="error">{{ error }}</p>
      <p v-else-if="!items.length" class="muted">目前沒有通知。</p>
      <button
        v-for="item in items.slice(0, 8)"
        :key="item.id"
        :data-notification-id="item.id"
        class="notification-item"
        type="button"
        :disabled="following || readingAll"
        @click="follow(item)"
      >
        <strong>{{
          item.type === "group_news" ? "新的小組消息" : item.title || item.type
        }}</strong
        ><span v-if="item.type !== 'group_news'">{{
          item.body || item.message
        }}</span
        ><small v-if="!item.read && !item.read_at">未讀</small>
      </button>
    </section>
  </div>
</template>
<style scoped>
.notification-bell {
  position: relative;
}
.bell {
  position: relative;
  display: grid;
  place-items: center;
  min-width: 44px;
  min-height: 44px;
  border: 1px solid #ffffff66;
  border-radius: 999px;
  background: #ffffff12;
  color: inherit;
  cursor: pointer;
}
.badge {
  position: absolute;
  top: -5px;
  right: -4px;
  min-width: 18px;
  padding: 1px 4px;
  border-radius: 99px;
  background: #c33;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
}
.notification-popover {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  z-index: 60;
  width: min(360px, calc(100vw - 24px));
  max-height: min(460px, 70dvh);
  overflow: auto;
  padding: 12px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: var(--paper);
  color: var(--ink);
  box-shadow: 0 16px 36px #0003;
}
.popover-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.read-all { width: 100%; min-height: 44px; margin-bottom: 8px; }
.notification-item {
  display: block;
  width: 100%;
  padding: 10px 4px;
  border: 0;
  border-top: 1px solid var(--line);
  background: transparent;
  color: inherit;
  text-align: left;
  font: inherit;
  cursor: pointer;
}
.notification-item strong,
.notification-item span,
.notification-item small {
  display: block;
}
.notification-item span {
  margin-top: 3px;
  color: var(--muted);
  font-size: 13px;
}
.notification-item small {
  color: var(--pine);
  margin-top: 4px;
}
</style>
