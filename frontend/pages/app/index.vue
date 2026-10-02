<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const { user, can, refresh } = useAuth();
const today = taipeiDate();
const sessions = ref<Session[]>([]), loading = ref(true);
const { error } = useApiError();
const mine = (s: Session) => s.assignments?.find(a => a.teacher_id === user.value?.id);
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const all = (await api<{ data: Session[] }>(`/sessions?from=${today}&to=${today}`)).data || [];
    sessions.value = all.filter(s => mine(s));
  } catch (e: any) { error.value = e.message; }
  finally { loading.value = false; }
}
async function sessionUpdated() { await load(); }
onMounted(async () => {
  await refresh();
  if (!can("schedule.read.own") && !can("schedule.read.all")) { loading.value = false; return; }
  await load();
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">MY SERVICE</p>
      <h1>
        {{
          can("schedule.read.own") || can("schedule.read.all")
            ? "今天的行程"
            : "我的服務入口"
        }}
      </h1>
      <p class="muted">{{ today }} · {{ user?.name }}，願你平安服事。</p>
    </div>
    <NuxtLink class="button" to="/app/calendar">查看行事曆</NuxtLink>
  </div>
  <div v-if="loading" class="empty">載入行程中…</div>
  <div v-else-if="error" class="notice">{{ error }}</div>
  <div
    v-else-if="!can('schedule.read.own') && !can('schedule.read.all')"
    class="card empty"
  >
    目前沒有排定的教學服務。您可以查看通知、填寫表單、下載授權資源，或管理個人資料與奉獻紀錄。
  </div>
  <div v-else-if="!sessions.length" class="card empty">
    今天沒有排定服務。請留意異動通知。
  </div>
  <div v-else class="grid">
    <article v-for="s in sessions" :key="s.id" class="card">
      <div class="workhead">
        <div>
          <span :class="['status', s.status]">{{
            s.status === "cancelled" ? "已取消" : "已排定"
          }}</span>
          <h3>{{ s.title }}</h3>
          <p class="muted">
            {{ s.service_date }} {{ s.start_time }}–{{ s.end_time }} ·
            {{ s.prison }}／{{ s.location }}<br />預計
            {{ s.participant_count }} 人
          </p>
        </div>
        <SessionActions
          v-if="!loading"
          :session="s"
          @updated="sessionUpdated"
        />
      </div>
      <div
        v-for="a in s.assignments"
        :key="a.id"
        class="notice"
        style="margin-top: 8px"
      >
        <AttendanceSummary :assignment="a" />
      </div>
    </article>
  </div>
</template>
