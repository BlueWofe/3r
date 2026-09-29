<script setup lang="ts">
definePageMeta({ layout: "app" });
import type { Session } from "~/types";
const rows = ref<Session[]>([]),
  teachers = ref<any[]>([]),
  modal = ref(false),
  edit = ref<Session | null>(null),
  from = ref(new Date().toISOString().slice(0, 10)),
  prison = ref(""),
  teacher = ref(""),
  status = ref(""),
  query = ref("");
const { error, run } = useApiError();
async function load() {
  const end = new Date(from.value);
  end.setDate(end.getDate() + 60);
  rows.value =
    (
      await api<any>(
        `/sessions?from=${from.value}&to=${end.toISOString().slice(0, 10)}&prison=${encodeURIComponent(prison.value)}&teacher_id=${teacher.value}&status=${status.value}&q=${encodeURIComponent(query.value)}`,
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
onMounted(async () => {
  await load();
  try {
    teachers.value = (await api<any>("/teachers")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">ADMIN SCHEDULE</p>
      <h1>排程管理</h1>
    </div>
    <div class="toolbar">
      <input v-model="from" type="date" @change="load" />
      <input v-model="prison" placeholder="監所" @change="load" />
      <select v-model="teacher" @change="load">
        <option value="">全部同工</option>
        <option v-for="t in teachers" :key="t.id" :value="t.id">
          {{ t.name }}
        </option>
      </select>
      <select v-model="status" @change="load">
        <option value="">全部狀態</option>
        <option value="scheduled">已排定</option>
        <option value="cancelled">已取消</option>
      </select>
      <input
        v-model="query"
        placeholder="搜尋主題"
        @keyup.enter="load"
      /><button
        class="button"
        @click="
          edit = null;
          modal = true;
        "
      >
        建立場次
      </button>
    </div>
  </div>
  <div class="notice">
    支援建立重複場次、教師指派與版本衝突保護；衝突可由後端回傳後重新調整。
  </div>
  <div class="tablewrap" style="margin-top: 16px">
    <table class="table">
      <thead>
        <tr>
          <th>日期時間</th>
          <th>服務</th>
          <th>地點</th>
          <th>同工</th>
          <th>狀態</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in rows" :key="s.id">
          <td>{{ s.service_date }} {{ s.start_time }}</td>
          <td>
            {{ s.title }}<br /><small>{{ s.prison }}</small>
          </td>
          <td>{{ s.location }}</td>
          <td>
            {{ assignmentSummary(s) }}<br /><small v-if="s.invitations?.length"
              >邀請中 {{ s.invitations.length }} 位同工</small
            >
          </td>
          <td>
            <span :class="['status', s.status]">{{ s.status }}</span>
          </td>
          <td>
            <SessionActions :session="s" admin @updated="load" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-if="error" class="error">{{ error }}</p>
  <SessionEditor
    v-if="modal"
    :session="edit"
    :teachers="teachers"
    @close="modal = false"
    @saved="load"
  />
</template>
