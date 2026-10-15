<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const { user, can, refresh } = useAuth();
const today = taipeiDate();
const selected = ref<Session | null>(null);
const pendingCount = computed(() => sessions.value.filter(s => s.status !== 'cancelled' && mine(s)?.status === 'assigned' && !mine(s)?.attendance).length);
const todayLabel = new Intl.DateTimeFormat('zh-TW', { month: 'long', day: 'numeric', weekday: 'long', timeZone: 'Asia/Taipei' }).format(new Date(`${today}T12:00:00+08:00`));
const hasSchedule = computed(() => can('schedule.read.own') || can('schedule.read.all'));

const sessions = ref<Session[]>([]), loading = ref(true);
const { error } = useApiError();
const mine = (s: Session) => { const own = s.assignments.filter(a => a.teacher_id === user.value?.id); return own.find(a => a.status === 'assigned') || own.find(a => a.status === 'leave') || own[0]; };
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
  <WorkspaceHero :title="hasSchedule ? '今天的服務日程' : '我的服務入口'" eyebrow="今天，也一起走" :subtitle="`${user?.name || ''}，平安。今天是 ${todayLabel}。${pendingCount ? `您有 ${pendingCount} 堂課程待簽到，請先查看當天服務提醒。` : '願每一次同行，都帶來盼望。'}`">
    <template #actions><button v-if="sessions.length" class="button" @click="selected = sessions[0] || null">查看課程與簽到</button><NuxtLink v-if="hasSchedule" class="button ghost" to="/app/calendar">查看我的行事曆</NuxtLink><NuxtLink v-else class="button" to="/app/invitations">查看通知</NuxtLink></template>
    <template #summary><dl><div><dt>同工姓名</dt><dd class="workspace-teacher">{{ user?.name }}</dd></div><div><dt>今日服務</dt><dd>{{ sessions.length }} 堂</dd></div><div><dt>待簽到</dt><dd>{{ pendingCount }} 堂</dd></div><div><dt>日期</dt><dd>{{ todayLabel }}</dd></div></dl></template>
  </WorkspaceHero>
  <div class="workspace-columns">
    <div class="workspace-stack">
      <div v-if="loading" class="empty" role="status">載入行程中…</div>
      <div v-else-if="error" class="error" role="alert">{{ error }}</div>
      <div v-else-if="!hasSchedule" class="card empty">目前沒有排定的教學服務。您可以查看通知、填寫表單、下載授權資源，或管理個人資料與奉獻紀錄。</div>
      <div v-else-if="!sessions.length" class="card empty">今天沒有排定服務。請留意異動通知。</div>
      <article v-for="s in sessions" :key="s.id" class="card today-session">
        <div class="today-session-meta"><span :class="['status', s.status]">{{ s.status === 'cancelled' ? '停課' : mine(s)?.attendance ? '已簽到' : mine(s)?.status === 'leave' ? '已請假' : '未簽到' }}</span><span class="muted">今天 {{ s.start_time }}–{{ s.end_time }}</span></div>
        <h3>{{ s.title }}</h3>
        <p class="muted">{{ s.class_name ? `${s.class_name} · ` : '' }}{{ s.prison }} · {{ s.location }}<br />預計 {{ s.participant_count }} 人</p>
        <div class="today-attendance"><AttendanceSummary v-for="a in s.assignments" :key="a.id" :assignment="a" :session-status="s.status" /></div>
        <div class="today-session-footer"><span class="muted">請先查看課程與簽到</span><SessionActions :session="s" detail-first @updated="sessionUpdated" /></div>
      </article>
      <section v-if="hasSchedule" class="card"><h3>服務提醒</h3><p class="muted">服務當日整天可簽到；照片與備註選填。過日補簽需填寫原因，系統會保留實際操作時間。</p><NuxtLink class="button ghost" to="/app/calendar">查看我的行事曆</NuxtLink></section>
    </div>
    <aside class="workspace-stack">
      <section class="card"><h3>同工資訊</h3><dl class="workspace-facts"><div><dt>姓名</dt><dd class="workspace-teacher">{{ user?.name }}</dd></div><div><dt>日期</dt><dd>{{ todayLabel }}</dd></div><div><dt>今日服務</dt><dd>{{ sessions.length }} 堂</dd></div><div><dt>待簽到</dt><dd>{{ pendingCount }} 堂</dd></div></dl></section>
      <section class="card"><h3>快速操作</h3><div class="workspace-stack"><NuxtLink v-if="hasSchedule" class="button" to="/app/calendar">查看我的行事曆</NuxtLink><NuxtLink class="button ghost" to="/app/invitations">邀請與通知</NuxtLink><NuxtLink class="button ghost" to="/app/profile">我的帳戶</NuxtLink></div></section>
    </aside>
  </div>
  <SessionActions v-if="selected" :session="selected" open-on-mount detail-first @updated="selected = null; sessionUpdated()" @close="selected = null" />
</template>
<style scoped>
.today-session-meta,.today-session-footer { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.today-session-meta { font-size:13px; } .today-session-footer { margin-top:20px; padding-top:16px; border-top:1px solid var(--r3-border); font-size:13px; }
.today-attendance { display:grid; gap:8px; }
@media(max-width:760px) { .today-session-footer { align-items:stretch; flex-direction:column; } }
</style>
