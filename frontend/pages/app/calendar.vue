<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const view = ref<"agenda" | "week" | "month">("month");
const cursor = ref(new Date());
const sessions = ref<Session[]>([]);
const selected = ref<Session | null>(null);
const refreshing = ref(false);
const error = ref("");
const yyyyMMdd = (d: Date) => {
  const z = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`;
};
const monday = (d: Date) => {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
  return x;
};
const range = computed(() => {
  if (view.value === "month") {
    const first = new Date(
      cursor.value.getFullYear(),
      cursor.value.getMonth(),
      1,
    );
    const from = monday(first),
      to = new Date(from);
    to.setDate(to.getDate() + 41);
    return { from, to, count: 42 };
  }
  const from = monday(cursor.value),
    count = view.value === "week" ? 7 : 14,
    to = new Date(from);
  to.setDate(to.getDate() + count - 1);
  return { from, to, count };
});
const load = async () => {
  try {
    sessions.value =
      (
        await api<any>(
          `/sessions?from=${yyyyMMdd(range.value.from)}&to=${yyyyMMdd(range.value.to)}`,
        )
      ).data || [];
    error.value = "";
  } catch (e: any) {
    error.value = e.message;
  }
};
onMounted(() => {
  if (window.innerWidth <= 760) view.value = "agenda";
  load();
});
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
  if (view.value === "month") d.setMonth(d.getMonth() + n);
  else d.setDate(d.getDate() + n * (view.value === "week" ? 7 : 14));
  cursor.value = d;
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
  <div class="calendar">
    <div
      v-for="d in days"
      :key="d"
      class="day"
      :style="view === 'agenda' ? 'grid-column:span 7' : ''"
    >
      <b
        >{{ d.slice(5) }}
        <small class="muted">{{
          new Date(d + "T00:00:00").toLocaleDateString("zh-TW", {
            weekday: "short",
          })
        }}</small></b
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
      </button>
    </div>
  </div>
  <SessionActions
    v-if="selected"
    :session="selected"
    open-on-mount
    @updated="sessionUpdated"
    @close="selected = null"
  />
</template>
