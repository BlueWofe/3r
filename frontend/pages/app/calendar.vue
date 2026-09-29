<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const view = ref<"agenda" | "week" | "month">("month");
const cursor = ref(new Date());
const sessions = ref<Session[]>([]);
const selected = ref<Session | null>(null);
const refreshing = ref(false);
const error = ref("");
const dayDialog = ref<HTMLDialogElement | null>(null);
const selectedDay = ref("");
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
  try {
    const next =
      (
        await api<any>(
          `/sessions?from=${yyyyMMdd(range.value.from)}&to=${yyyyMMdd(range.value.to)}`,
        )
      ).data || [];
    if (sequence === request) {
      sessions.value = next;
      error.value = "";
    }
  } catch (e: any) {
    if (sequence === request) error.value = e.message;
  }
};
onMounted(load);
watch([view, cursor], load);
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
  sessions.value.filter((session) => session.service_date === date);
function openDay(date: string) {
  selectedDay.value = date;
  dayDialog.value?.showModal();
}
function openSession(session: Session) {
  dayDialog.value?.close();
  selected.value = session;
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
        @click="view = v as any"
      >
        {{ v === "agenda" ? "議程" : v === "week" ? "週" : "月" }}
      </button>
    </div>
  </div>
  <div v-if="error" class="notice">{{ error }}</div>
  <div class="calendar iphone-calendar" :class="{ agenda: view === 'agenda' }">
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
        :aria-label="`${d}，${sessionsFor(d).length} 場服務`"
        @click="openDay(d)"
      >
        <b
          >{{ d.slice(5) }}
          <small class="muted">{{
            new Date(d + "T00:00:00").toLocaleDateString("zh-TW", {
              weekday: "short",
            })
          }}</small></b
        ><span class="event-bars" :aria-hidden="true"
          ><i
            v-for="s in sessionsFor(d).slice(0, 3)"
            :key="s.id"
            :class="s.status"
          ></i></span
        ><small v-if="sessionsFor(d).length > 3"
          >+{{ sessionsFor(d).length - 3 }}</small
        ></button
      ><template v-if="view === 'agenda'"
        ><button
          v-for="s in sessions.filter((x) => x.service_date === d)"
          :key="s.id"
          class="event"
          :disabled="refreshing"
          @click="selected = s"
        >
          <span :class="['status', s.status]">{{
            s.status === "cancelled" ? "取消" : "排定"
          }}</span>
          {{ s.start_time }} {{ s.title }}
        </button></template
      >
    </div>
  </div>
  <p v-if="view !== 'agenda'" class="calendar-legend">
    <span class="legend scheduled"></span>已排定
    <span class="legend cancelled"></span>已取消；點選日期查看場次
  </p>
  <dialog ref="dayDialog" class="day-sheet" @close="selectedDay = selectedDay">
    <div class="workhead">
      <h2>{{ selectedDay }}</h2>
      <button class="button ghost" @click="dayDialog?.close()">關閉</button>
    </div>
    <p v-if="!sessionsFor(selectedDay).length" class="muted">
      這一天沒有服務場次。
    </p>
    <button
      v-for="session in sessionsFor(selectedDay)"
      :key="session.id"
      class="day-session"
      @click="openSession(session)"
    >
      <span :class="['status', session.status]">{{
        session.status === "cancelled" ? "已取消" : "已排定"
      }}</span
      ><b>{{ session.start_time }} {{ session.title }}</b
      ><small>{{ session.prison }}／{{ session.location }}</small>
    </button>
  </dialog>
  <SessionActions
    v-if="selected"
    :session="selected"
    open-on-mount
    @updated="sessionUpdated"
    @close="selected = null"
  />
</template>
<style scoped>
.calendar-day-tap {
  display: block;
  width: 100%;
  min-height: 82px;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  padding: 4px;
  cursor: pointer;
}
.event-bars {
  display: flex;
  gap: 2px;
  margin-top: 5px;
}
.event-bars i {
  display: block;
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: #3d8768;
}
.event-bars i.cancelled {
  background: #b35a4d;
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
  background: #b35a4d;
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
  }
  .iphone-calendar:not(.agenda) {
    grid-template-columns: repeat(7, minmax(0, 1fr));
    overflow: hidden;
    gap: 2px;
  }
  .iphone-calendar:not(.agenda) .day {
    min-width: 0;
    min-height: 88px;
    padding: 2px;
  }
  .calendar-day-tap {
    min-height: 82px;
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
    max-height: 80dvh;
  }
  .agenda .event {
    min-height: 44px;
  }
}
</style>
