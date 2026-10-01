export interface InboxNotification {
  id: number;
  category?: "product" | "course" | "message" | "contact" | string;
  type?: string;
  title?: string;
  body?: string;
  message?: string;
  url?: string | null;
  read?: boolean;
  read_at?: string | null;
  created_at?: string;
}

export function useNotifications() {
  const { user } = useAuth();
  const items = useState<InboxNotification[]>("notifications.items", () => []);
  const unreadCount = useState<number>("notifications.unread", () => 0);
  const loading = useState<boolean>("notifications.loading", () => false);
  const error = useState<string>("notifications.error", () => "");
  const requestVersion = useState<number>("notifications.request-version", () => 0);
  watch(() => user.value?.id, () => {
    requestVersion.value++;
    items.value = [];
    unreadCount.value = 0;
    loading.value = false;
    error.value = "";
  });
  async function refreshNotifications() {
    const version = ++requestVersion.value;
    const ownerId = user.value?.id;
    loading.value = true; error.value = "";
    try {
      const result = await api<any>("/notifications");
      if (version !== requestVersion.value || ownerId !== user.value?.id) return;
      items.value = result.data || [];
      unreadCount.value = Number.isFinite(Number(result.unread_count)) ? Number(result.unread_count) : items.value.filter((item) => !item.read && !item.read_at).length;
    } catch (e: any) {
      if (version === requestVersion.value && ownerId === user.value?.id) error.value = e.message || "通知暫時無法載入。";
      throw e;
    } finally { if (version === requestVersion.value) loading.value = false; }
  }
  async function markNotificationRead(item: InboxNotification) {
    const ownerId = user.value?.id;
    const wasUnread = !item.read && !item.read_at;
    const updated = await api<InboxNotification>(`/notifications/${item.id}/read`, { method: "POST" });
    if (ownerId !== user.value?.id) throw new Error("帳號已變更，請重新開啟通知。");
    Object.assign(item, updated);
    item.read = true;
    if (wasUnread) unreadCount.value = Math.max(0, unreadCount.value - 1);
    return updated;
  }
  async function markAllNotificationsRead() {
    const ownerId = user.value?.id;
    const result = await api<any>("/notifications/read-all", { method: "POST" });
    if (ownerId !== user.value?.id) throw new Error("帳號已變更，請重新載入通知。");
    unreadCount.value = Number(result.unread_count || 0);
    await refreshNotifications();
    return result;
  }
  return { items, unreadCount, loading, error, refreshNotifications, markNotificationRead, markAllNotificationsRead };
}

export function notificationDestination(value?: string | null) {
  if (!value || !value.startsWith("/app/")) return null;
  try {
    const parsed = new URL(value, "http://notification.local");
    if (parsed.origin !== "http://notification.local" || !parsed.pathname.startsWith("/app/")) return null;
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch { return null; }
}
