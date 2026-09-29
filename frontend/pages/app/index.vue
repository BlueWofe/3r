<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const { user, can, refresh } = useAuth();
const today = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Taipei",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
})
  .format(new Date())
  .replaceAll("/", "-");
const sessions = ref<Session[]>([]),
  loading = ref(true),
  action = ref<any>(null),
  reason = ref(""),
  photo = ref<File | null>(null),
  teachers = ref<any[]>([]),
  teacherId = ref<number | undefined>(),
  editTitle = ref(""),
  sessionEdit = ref<Session | null>(null);
const { error, run } = useApiError();
const mine = (s: any) =>
  s.assignments?.find((a: any) => a.teacher_id === user.value?.id);
async function load() {
  loading.value = true;
  try {
    const all =
      (await api<any>(`/sessions?from=${today}&to=${today}`)).data || [];
    sessions.value = all.filter((s: any) => mine(s));
  } catch (e: any) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
async function assignmentAction(a: any, kind: string) {
  const body: any = { version: action.value.version, reason: reason.value };
  if (kind === "invite" || kind === "replace")
    body.teacher_id = teacherId.value;
  await run(() =>
    api(`/assignments/${a.id}/${kind}`, { method: "POST", body }),
  );
  action.value = null;
  await load();
}
async function attendance(a: any) {
  const fd = new FormData();
  if (photo.value) fd.append("photo", photo.value);
  fd.append("reason", reason.value);
  await run(() =>
    api(`/assignments/${a.id}/attendance`, { method: "POST", body: fd }),
  );
  action.value = null;
  await load();
}
async function updateSession(status?: string) {
  await run(() =>
    api(`/sessions/${action.value.id}`, {
      method: "PUT",
      body: {
        version: action.value.version,
        reason: reason.value,
        title: editTitle.value || action.value.title,
        status,
      },
    }),
  );
  action.value = null;
  await load();
}
async function sessionUpdated() {
  await load();
}
onMounted(async () => {
  await refresh();
  if (!can("schedule.read.own") && !can("schedule.read.all")) {
    loading.value = false;
    return;
  }
  await load();
  if (can("schedule.update.own") || can("schedule.update.all")) {
    try {
      teachers.value = (await api<any>("/teachers")).data || [];
    } catch (e: any) {
      error.value = e.message;
    }
  }
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
        {{ a.teacher?.name }}：{{ a.status }}
        <span v-if="a.attendance"> · 已完成簽到</span>
      </div>
    </article>
  </div>
  <div v-if="action" class="modal">
    <div class="dialog">
      <div class="workhead">
        <h2>{{ action.title }}</h2>
        <button class="button ghost" @click="action = null">關閉</button>
      </div>
      <p class="muted">請填寫異動原因；可用的操作會依目前的服務狀態顯示。</p>
      <label class="field"
        >異動原因／備註<textarea
          v-model="reason"
          required
          placeholder="請填寫原因"
        ></textarea></label
      ><label
        v-if="can('schedule.update.own') || can('schedule.update.all')"
        class="field"
        >受邀／替代同工<select v-model="teacherId">
          <option :value="undefined">請選擇</option>
          <option v-for="t in teachers" :key="t.id" :value="t.id">
            {{ t.name }}
          </option>
        </select></label
      ><label class="field"
        >簽到照片（選填）<input
          type="file"
          accept="image/*"
          @change="
            photo = ($event.target as HTMLInputElement).files?.[0] || null
          "
      /></label>
      <div class="actions">
        <button
          v-if="mine(action)?.status === 'assigned'"
          class="button"
          @click="assignmentAction(mine(action), 'leave')"
        >
          請假</button
        ><button
          v-if="mine(action)?.status === 'leave'"
          class="button ghost"
          @click="assignmentAction(mine(action), 'withdraw-leave')"
        >
          撤回請假</button
        ><button
          v-if="mine(action)?.status === 'leave'"
          class="button ghost"
          @click="assignmentAction(mine(action), 'invite')"
        >
          邀請代課</button
        ><button
          v-if="can('schedule.update.all')"
          class="button ghost"
          @click="assignmentAction(mine(action), 'replace')"
        >
          重新指派</button
        ><button
          v-if="can('schedule.update.own') || can('schedule.update.all')"
          class="button ghost"
          @click="sessionEdit = action"
        >
          編輯場次</button
        ><button
          v-if="can('schedule.update.own') || can('schedule.update.all')"
          class="button danger"
          @click="updateSession('cancelled')"
        >
          取消場次</button
        ><button
          v-if="mine(action)?.status === 'assigned'"
          class="button gold"
          @click="attendance(mine(action))"
        >
          完成簽到
        </button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </div>
  </div>
  <SessionEditor
    v-if="sessionEdit"
    :session="sessionEdit"
    :teachers="teachers"
    @close="sessionEdit = null"
    @saved="load"
  />
</template>
