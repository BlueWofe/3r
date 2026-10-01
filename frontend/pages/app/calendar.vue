<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
type CalendarEntry = Session & { activity?: any };
const { can } = useAuth();
const route = useRoute();
const view = ref<"agenda" | "week" | "month">("month");
const cursor = ref(new Date());
const sessions = ref<CalendarEntry[]>([]);
const selected = ref<Session | null>(null);
const selectedActivity = ref<any>(null);
const refreshing = ref(false);
const loading = ref(false);
const error = ref("");
const dayDialog = ref<HTMLDialogElement | null>(null);
const selectedDay = ref("");
const focusedSessionId = ref<number | null>(null);
const today = yyyyToday();
function yyyyToday() { return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Taipei" }).format(new Date()); }
let previousOverflow = "";
let dayTrigger: HTMLElement | null = null;
let request = 0;
const yyyyMMdd = (d: Date) => {
  const z = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`;
};
const sunday = (d: Date) => {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  x.setDate(x.getDate() - x.getDay());
  return x;
};
const range = computed(() => {
  if (view.value === "month") {
    const first = new Date(
      cursor.value.getFullYear(),
      cursor.value.getMonth(),
      1,
    );
    const from = sunday(first),
      to = new Date(from);
    to.setDate(to.getDate() + 41);
    return { from, to, count: 42 };
  }
  const from = sunday(cursor.value),
    count = view.value === "week" ? 7 : 14,
    to = new Date(from);
  to.setDate(to.getDate() + count - 1);
  return { from, to, count };
});
const load = async () => {
  const sequence = ++request;
  loading.value = true;
  try {
    const from = yyyyMMdd(range.value.from), to = yyyyMMdd(range.value.to);
    const [courses, meetings] = await Promise.all([
      can("schedule.read.all") || can("schedule.read.own")
        ? api<any>(`/sessions?from=${from}&to=${to}`) : Promise.resolve({ data: [] }),
      can("meetings.read.all") || can("meetings.read.own")
        ? api<any>("/meetings") : Promise.resolve({ data: [] }),
    ]);
    const activities: CalendarEntry[] = (meetings.data || [])
      .filter((m: any) => m.show_on_calendar === true && m.meeting_date >= from && m.meeting_date <= to)
      .map((m: any) => ({
        id: -m.id, title: m.title, class_name: m.kind === "activity" ? m.activity_type || "活動" : "會議",
        prison: "", location: m.location || "", service_date: m.meeting_date,
        start_time: m.start_time || "", end_time: m.end_time || "", status: "scheduled",
        color: m.color, version: 1, participant_count: 0, assignments: [], invitations: [], events: [], activity: m,
      }));
    const next = [...(courses.data || []), ...activities];
    if (sequence === request) {
      sessions.value = next;
      error.value = "";
    }
  } catch (e: any) {
    if (sequence === request) { sessions.value = []; error.value = e.message; }
  } finally {
    if (sequence === request) loading.value = false;
  }
};
async function openLinkedSession() {
  focusedSessionId.value = null;
  const raw = route.query.session_id;
  if (raw === undefined) return;
  selected.value = null; selectedActivity.value = null; dayDialog.value?.close();
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) { error.value = "通知指定的課程編號無效。"; return; }
  try {
    const target = await api<Session>(`/sessions/${id}`);
    const date = new Date(`${target.service_date}T12:00:00`);
    if (Number.isNaN(date.getTime())) throw new Error("課程日期資料無效。");
    view.value = "agenda";
    cursor.value = date;
    await nextTick();
    await load();
    if (!sessions.value.some(session => session.id === id)) throw new Error("找不到指定的課程，或您已無權查看。");
    focusedSessionId.value = id;
    await nextTick();
    document.querySelector(`[data-session-id="${id}"]`)?.scrollIntoView({ block: "center" });
  } catch (e: any) { focusedSessionId.value = null; error.value = e.message || "找不到指定的課程。"; }
}
onMounted(async () => { await load(); await openLinkedSession(); });
watch([view, cursor], load);
watch(() => route.query.session_id, openLinkedSession);
const days = computed(() =>
  Array.from({ length: range.value.count }, (_, i) => {
    const d = new Date(range.value.from);
    d.setDate(d.getDate() + i);
    return yyyyMMdd(d);
  }),
);
const label = computed(() =>
  view.value === "month"
    ? `${cursor.value.getFullYear()} 年 ${cursor.value.getMonth() + 1} 月`
    : `${yyyyMMdd(range.value.from)} 至 ${yyyyMMdd(range.value.to)}`,
);
function shift(n: number) {
  const d = new Date(cursor.value);
  if (view.value === "month") {
    d.setDate(1);
    d.setMonth(d.getMonth() + n);
  } else d.setDate(d.getDate() + n * (view.value === "week" ? 7 : 14));
  cursor.value = d;
}
const sessionsFor = (date: string) =>
  sessions.value.filter((session) => session.service_date === date)
    .sort((a, b) => a.start_time.localeCompare(b.start_time) || a.id - b.id);
function openDay(date: string, event: MouseEvent) {
  dayTrigger = event.currentTarget as HTMLElement;
  selectedDay.value = date;
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = "hidden";
  dayDialog.value?.showModal();
}
function closeDay() {
  document.body.style.overflow = previousOverflow;
  if (!selected.value && !selectedActivity.value) dayTrigger?.focus({ preventScroll: true });
}
onBeforeUnmount(() => {
  if (dayDialog.value?.open) closeDay();
});
async function openSession(session: CalendarEntry) {
  dayDialog.value?.close();
  if (session.activity) {
    try {
      selectedActivity.value = await api<any>(`/meetings/${session.activity.id}`);
    } catch (e: any) { error.value = e.message; await load(); }
  } else selected.value = session;
}
async function sessionUpdated() {
  refreshing.value = true;
  try {
    await load();
  } finally {
    selected.value = null;
    refreshing.value = false;
  }
}
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">SCHEDULE</p>
      <h1>行事曆</h1>
      <p class="muted">{{ label }}</p>
    </div>
    <div class="toolbar">
      <button class="button ghost" @click="shift(-1)">←</button
      ><button class="button ghost" @click="cursor = new Date()">今天</button
      ><button class="button ghost" @click="shift(1)">→</button
      ><button
        v-for="v in ['agenda', 'week', 'month']"
        :key="v"
        :class="['button', view === v ? '' : 'ghost']"
        :aria-pressed="view === v"
        @click="view = v as any"
      >
        {{ v === "agenda" ? "議程" : v === "week" ? "週" : "月" }}
      </button>
    </div>
  </div>
  <p v-if="view !== 'agenda'" class="calendar-legend">
    顏色依班別／課程設定；<span class="legend cancelled"></span>斜線表示已取消。點選日期查看場次
  </p>
  <div v-if="error" class="notice">{{ error }}</div>
  <p v-if="loading" role="status" class="muted">正在載入課程…</p>
  <div v-if="view !== 'agenda'" class="calendar-weekdays" aria-hidden="true">
    <span v-for="weekday in ['日', '一', '二', '三', '四', '五', '六']" :key="weekday">{{ weekday }}</span>
  </div>
  <div class="calendar iphone-calendar" :aria-busy="loading" :class="{ agenda: view === 'agenda' }">
    <div
      v-for="d in days"
      :key="d"
      class="day"
      :data-date="d"
      :style="view === 'agenda' ? 'grid-column:span 7' : ''"
    >
      <button
        v-if="view !== 'agenda'"
        class="calendar-day-tap"
        :class="{ 'is-today': d === today, 'other-month': view === 'month' && Number(d.slice(5, 7)) !== cursor.getMonth() + 1 }"
        :aria-current="d === today ? 'date' : undefined"
        :disabled="loading || refreshing"
        :aria-label="loading ? `${d}，載入中` : `${d}，${sessionsFor(d).length} 筆行程`"
        @click="openDay(d, $event)"
      >
        <b>{{ Number(d.slice(8)) }}</b
        ><span class="event-bars" :aria-hidden="true"
          ><i
            v-for="s in sessionsFor(d).slice(0, 3)"
            :key="s.id"
            :class="s.status"
            :style="{ backgroundColor: scheduleColor(s.color) }"
          ></i></span
        ><small v-if="sessionsFor(d).length > 3"
          >+{{ sessionsFor(d).length - 3 }}</small
        ></button
      ><template v-if="view === 'agenda'"
        ><b class="agenda-date">{{ d }} {{ new Date(d + 'T00:00:00').toLocaleDateString('zh-TW', { weekday: 'short' }) }}</b><button
          v-for="s in sessionsFor(d)"
          :key="s.id"
          class="event"
          :class="{ 'notification-focus': focusedSessionId === s.id }"
          :data-session-id="s.id"
          :style="{ borderLeft: `5px solid ${scheduleColor(s.color)}` }"
          :disabled="refreshing || loading"
          @click="openSession(s)"
        >
          <span :class="['status', s.status]">{{
            s.activity ? (s.activity.kind === "activity" ? "活動" : "會議") : s.status === "cancelled" ? "取消" : "排定"
          }}</span>
          {{ s.start_time || "全天" }} {{ s.title }}
        </button></template
      >
    </div>
  </div>

  <dialog ref="dayDialog" class="day-sheet" aria-labelledby="day-sheet-title" @close="closeDay">
    <div class="workhead">
      <h2 id="day-sheet-title">{{ selectedDay }} 的行程</h2>
      <button class="button ghost" @click="dayDialog?.close()">關閉</button>
    </div>
    <p v-if="!sessionsFor(selectedDay).length" class="muted">
      這一天沒有行程。
    </p>
    <button
      v-for="session in sessionsFor(selectedDay)"
      :key="session.id"
      class="day-session"
      :style="{ borderLeft: `5px solid ${scheduleColor(session.color)}` }"
      @click="openSession(session)"
    >
      <span :class="['status', session.status]">{{
        session.activity ? (session.activity.kind === "activity" ? "活動" : "會議") : session.status === "cancelled" ? "已取消" : "已排定"
      }}</span
      ><b>{{ session.start_time || "全天" }} {{ session.title }}</b
      ><small>{{ session.class_name ? `${session.class_name}・` : "" }}{{ [session.prison, session.location].filter(Boolean).join("／") }}</small>
    </button>
  </dialog>
  <SessionActions
    v-if="selected"
    :session="selected"
    open-on-mount
    @updated="sessionUpdated"
    @close="selected = null"
  />
  <div v-if="selectedActivity" class="modal">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="activity-detail-title">
      <div class="workhead"><h2 id="activity-detail-title">{{ selectedActivity.title }}</h2><button class="button ghost" @click="selectedActivity = null">關閉</button></div>
      <p>{{ selectedActivity.kind === "activity" ? selectedActivity.activity_type || "活動" : "會議" }} · {{ selectedActivity.meeting_date }} · {{ selectedActivity.start_time ? `${selectedActivity.start_time}–${selectedActivity.end_time}` : "全天" }}</p>
      <p v-if="selectedActivity.location">地點：{{ selectedActivity.location }}</p>
      <p v-if="selectedActivity.agenda" class="activity-text">{{ selectedActivity.agenda }}</p>
      <NuxtLink class="button ghost" to="/app/admin/meetings">前往會議／活動管理</NuxtLink>
    </section>
  </div>
</template>
<style scoped>
.activity-text { white-space: pre-wrap; }
.notification-focus { background: #fff8e8; box-shadow: 0 0 0 3px rgba(198, 157, 77, .35); }
.calendar-weekdays { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); text-align: center; padding: 8px 0; font-size: 13px; color: var(--muted); }
.iphone-calendar:not(.agenda) { grid-template-columns: repeat(7, minmax(0, 1fr)); }
.iphone-calendar:not(.agenda) .day { height: 106px; min-height: 0; min-width: 0; padding: 4px; overflow: hidden; }
.calendar-day-tap b { display: inline-grid; place-items: center; width: 26px; height: 26px; }
.calendar-day-tap.is-today b { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; color: white; background: var(--pine); }
.calendar-day-tap.other-month { color: #737873; background: #f2f1eb; }
.calendar-day-tap:focus-visible, .day-session:focus-visible { outline: 2px solid var(--pine); outline-offset: -2px; }
.agenda-date { display: block; }
.calendar-day-tap {
  display: block;
  width: 100%;
  height: 100%;
  min-height: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: center;
  padding: 4px;
  cursor: pointer;
}
.event-bars {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 5px;
  min-height: 23px;
}
.event-bars i {
  display: block;
  flex: 0 0 5px;
  height: 5px;
  border-radius: 999px;
  background: #3d8768;
  box-shadow: inset 0 0 0 1px #0002;
}
.event-bars i.cancelled {
  background-image: repeating-linear-gradient(135deg, transparent, transparent 4px, #ffffffaa 4px, #ffffffaa 6px);
}
.calendar-legend {
  font-size: 13px;
  color: var(--muted);
}
.legend {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #3d8768;
  margin: 0 4px 0 12px;
}
.legend.cancelled {
  background: repeating-linear-gradient(135deg, #626e7a, #626e7a 3px, #ffffffaa 3px, #ffffffaa 5px);
}
.day-sheet {
  width: min(680px, 100%);
  max-height: min(78dvh, 700px);
  border: 0;
  border-radius: 16px 16px 0 0;
  padding: 18px;
  color: var(--ink);
  background: var(--paper);
  overflow-y: auto;
  margin: auto auto 0;
}
.day-sheet::backdrop {
  background: #0008;
}
.day-sheet .workhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  position: sticky;
  top: -18px;
  background: var(--paper);
  padding: 12px 0;
  margin: 0;
  z-index: 1;
}
.day-sheet h2 { font-size: 18px; margin: 0; }
.day-sheet .workhead .button { flex: 0 0 auto; width: auto; min-height: 44px; padding: 6px 10px; }
.day-session {
  display: grid;
  gap: 6px;
  width: 100%;
  min-height: 64px;
  margin: 8px 0;
  padding: 12px;
  text-align: left;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  color: inherit;
  cursor: pointer;
  overflow-wrap: anywhere;
}
.day-session small {
  color: var(--muted);
}
@media (max-width: 760px) {
  .workhead .toolbar {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 4px;
  }
  .workhead .toolbar .button {
    min-width: 0;
    padding: 6px 2px;
    font-size: 12px;
    min-height: 44px;
  }
  .iphone-calendar:not(.agenda) {
    grid-template-columns: repeat(7, minmax(0, 1fr));
    overflow: hidden;
    gap: 2px;
  }
  .iphone-calendar:not(.agenda) .day {
    min-width: 0;
    height: 88px;
    min-height: 0;
    padding: 2px;
  }
  .calendar-day-tap {
    min-height: 0;
    font-size: 11px;
    overflow: hidden;
  }
  .calendar-day-tap small {
    display: block;
    font-size: 10px;
  }
  .iphone-calendar:not(.agenda) .day > .event {
    display: none;
  }
  .day-sheet {
    width: 100%;
    max-width: 100%;
    max-height: 80dvh;
  }
  .agenda .event {
    min-height: 44px;
  }
}
</style>
