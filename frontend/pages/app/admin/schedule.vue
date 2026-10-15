<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const rows = ref<Session[]>([]),
  teachers = ref<any[]>([]),
  prisons = ref<any[]>([]),
  modal = ref(false),
  edit = ref<Session | null>(null),
  from = ref(taipeiDate()),
  prison = ref<number | "">(""),
  teacher = ref(""),
  status = ref(""),
  query = ref(""),
  refreshing = ref(false),
  focusedSessionId = ref<number | null>(null),
  actionsKey = ref(0);
const route = useRoute();
const { error, run } = useApiError();
function attendanceState(s: Session) {
  if (s.status === 'cancelled') return { label: '停課', tone: 'cancelled' };
  const assigned = s.assignments.filter(a => a.status === 'assigned');
  if (!assigned.length || s.assignments.some(a => a.status === 'leave')) return { label: '請假缺額', tone: 'warning' };
  if (assigned.every(a => a.attendance)) return { label: '已簽到', tone: 'scheduled' };
  return { label: '未簽到', tone: 'scheduled' };
}

async function load() {
  const end = new Date(from.value);
  end.setDate(end.getDate() + 60);
  rows.value =
    (
      await api<any>(
        `/sessions?from=${from.value}&to=${end.toISOString().slice(0, 10)}&prison_id=${prison.value}&teacher_id=${teacher.value}&status=${status.value}&q=${encodeURIComponent(query.value)}`,
      )
    ).data || [];
}
async function cancel(s: Session) {
  if (!confirm(`確定取消「${s.title}」？`)) return;
  await run(() =>
    api(`/sessions/${s.id}`, {
      method: "PUT",
      body: {
        version: s.version,
        status: "cancelled",
        reason: "管理者取消場次",
      },
    }),
  );
  await load();
}
function assignmentSummary(s: Session) {
  return (
    s.assignments
      .map(
        (a: any) =>
          `${a.teacher?.name || "待指派"}：${a.status === "leave" ? "請假" : a.status === "replaced" ? "已換師" : "已指派"}${a.attendance ? "（已簽到）" : ""}`,
      )
      .join("、") || "缺額"
  );
}
async function scheduleUpdated() {
  refreshing.value = true;
  try {
    await load();
  } finally {
    refreshing.value = false;
  }
}
async function openLinkedSession() {
  const id = Number(route.query.session_id);
  focusedSessionId.value = null;
  if (route.query.session_id !== undefined) { modal.value = false; edit.value = null; actionsKey.value++; }
  if (!Number.isInteger(id) || id <= 0) { if (route.query.session_id !== undefined) error.value = "通知指定的課程編號無效。"; return; }
  try {
    const target = await api<Session>(`/sessions/${id}`);
    prison.value = ""; teacher.value = ""; status.value = ""; query.value = "";
    from.value = target.service_date;
    await load();
    if (!rows.value.some(session => session.id === id)) throw new Error("找不到指定的課程，或您已無權查看。");
    focusedSessionId.value = id;
    await nextTick();
    document.querySelector(`[data-session-id="${id}"]`)?.scrollIntoView({ block: "center" });
  } catch (e: any) { focusedSessionId.value = null; error.value = e.message || "找不到指定的課程。"; }
}
onMounted(async () => {
  await load();
  try {
    teachers.value = (await api<any>("/teachers")).data || [];
    prisons.value = (await api<any>("/prisons/options")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
  await openLinkedSession();
});
watch(() => route.query.session_id, openLinkedSession);
</script>
<template>
  <div class="workhead"><div><p class="eyebrow">課務管理</p><h1>排程管理</h1><p class="muted">依日期、監所與老師快速篩選課程，同時一目了然課程狀態、時段與缺額。</p></div></div>
  <div class="schedule-filters">
    <label class="field">日期<input v-model="from" type="date" @change="load" /></label>
    <label class="field">監所<select v-model="prison" @change="load"><option value="">全部監所</option><option v-for="item in prisons" :key="item.id" :value="item.id">{{ item.name }}{{ item.active === false ? '（已停用）' : '' }}</option></select></label>
    <label class="field">老師<select v-model="teacher" @change="load"><option value="">全部老師</option><option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
    <details class="schedule-extra-filters"><summary>更多篩選條件</summary><div class="schedule-extra-fields">
    <label class="field">場次狀態<select v-model="status" @change="load"><option value="">全部狀態</option><option value="scheduled">已排定</option><option value="cancelled">已取消</option></select></label>
    <label class="field schedule-search">搜尋課程<input v-model="query" placeholder="課程主題、班級或上課位置" @keyup.enter="load" /></label>
    <button class="button ghost" @click="load">搜尋</button>
    </div></details>
  </div>
  <div class="actions schedule-create"><button class="button" @click="edit = null; modal = true">新增課程</button><NuxtLink class="button ghost" to="/app/admin/classes">管理班別</NuxtLink></div>
  <div class="tablewrap" style="margin-top: 16px">
    <div class="workspace-section-head"><div><h2>排課總覽</h2><p>依課程狀態與時段快速判讀</p></div><span class="muted">{{ rows.length }} 筆課程</span></div>
    <table class="table schedule-table">
      <thead>
        <tr>
          <th>日期時間</th>
          <th>服務</th>
          <th>班級名稱</th>
          <th>上課位置</th>
          <th>同工</th>
          <th>狀態</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in rows" :key="s.id" :data-session-id="s.id" :class="{ 'linked-session': focusedSessionId === s.id }">
          <td data-label="日期時間">{{ s.service_date }} {{ s.start_time }}</td>
          <td data-label="服務">
            <span class="class-color" :style="{ backgroundColor: scheduleColor(s.color) }" aria-hidden="true"></span>{{ s.title }}<br /><small>{{ s.prison }}</small>
          </td>
          <td data-label="班級名稱">{{ s.class_name || "—" }}</td>
          <td data-label="上課位置">{{ s.location }}</td>
          <td data-label="老師" class="workspace-teacher">
            {{ assignmentSummary(s) }}<br /><small v-if="s.invitations?.length"
              >邀請中 {{ s.invitations.length }} 位同工</small
            >
          </td>
          <td data-label="狀態">
            <span :class="['status', attendanceState(s).tone]">{{ attendanceState(s).label }}</span><small class="schedule-state-caption">{{ s.status === 'cancelled' ? '此場次已取消' : '課程已排定' }}</small>
          </td>
          <td data-label="操作">
            <SessionActions
              v-if="!refreshing"
              :key="`${s.id}-${actionsKey}`"
              :session="s"
              detail-first
              admin
              @updated="scheduleUpdated"
            /><span v-else class="muted">更新中…</span>
          </td>
        </tr>
        <tr v-if="!rows.length"><td colspan="7" class="empty">此區間沒有符合條件的課程。</td></tr>
      </tbody>
    </table>
    <footer class="workspace-list-footer">共 {{ rows.length }} 筆課程 · 依目前篩選條件顯示</footer>
  </div>
  <p v-if="error" class="error">{{ error }}</p>
  <SessionEditor
    v-if="modal"
    :session="edit"
    :teachers="teachers"
    :prisons="prisons"
    @close="modal = false"
    @saved="load"
  />
</template>
<style scoped>
.schedule-filters { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; align-items:end; }
.schedule-search { grid-column:span 1; }
.schedule-extra-filters { grid-column:1/-1; } .schedule-extra-filters summary { cursor:pointer; color:var(--r3-primary); font-weight:500; padding:8px 0; } .schedule-extra-fields { display:grid; grid-template-columns:1fr 2fr auto; gap:16px; align-items:end; padding-top:16px; }
.schedule-create { margin:24px 0 32px; }
.schedule-state-caption { display:block; margin-top:8px; }
@media(max-width:760px) { .schedule-filters,.schedule-extra-fields { grid-template-columns:minmax(0,1fr); } .schedule-create { flex-direction:column; align-items:stretch; } }

.linked-session { outline: 3px solid rgba(198, 157, 77, .55); outline-offset: -3px; background: #fff8e8; }
</style>
