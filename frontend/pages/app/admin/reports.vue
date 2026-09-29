<script setup lang="ts">
definePageMeta({ layout: "app" });
const today = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Taipei",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
})
  .format(new Date())
  .replaceAll("/", "-");
const from = ref(today.slice(0, 8) + "01"),
  to = ref(today),
  teacherId = ref(""),
  teachers = ref<any[]>([]),
  report = ref<any>(null),
  loading = ref(false);
const { error } = useApiError();
const rate = (value: any) =>
  value === null || value === undefined
    ? "—"
    : `${Math.round(Number(value) * 100)}%`;
async function load() {
  loading.value = true;
  error.value = "";
  try {
    report.value = await api(
      `/reports?from=${from.value}&to=${to.value}&teacher_id=${teacherId.value}`,
    );
  } catch (e: any) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(async () => {
  await load();
  try {
    teachers.value = (await api<any>("/teachers")).data || [];
  } catch {
    /* own-scope users do not require a teacher picker */
  }
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">SERVICE INSIGHT</p>
      <h1>服務報表</h1>
      <p class="muted">依日期與授權範圍彙整完成服務、出席、請假與代課情形。</p>
    </div>
    <form class="toolbar" @submit.prevent="load">
      <input v-model="from" type="date" /><input
        v-model="to"
        type="date"
      /><select v-if="teachers.length" v-model="teacherId">
        <option value="">所有可查看同工</option>
        <option v-for="t in teachers" :key="t.id" :value="t.id">
          {{ t.name }}
        </option></select
      ><button class="button">更新報表</button>
    </form>
  </div>
  <div v-if="loading" class="empty">彙整報表中…</div>
  <div v-else-if="error" class="notice">{{ error }}</div>
  <template v-else-if="report"
    ><div class="grid cards">
      <article class="card">
        <span class="eyebrow">完成服務</span>
        <h2>{{ report.summary?.completed_sessions || 0 }} 場</h2>
        <p class="muted">
          服務時數 {{ report.summary?.service_hours || 0 }} 小時 · 停課
          {{ report.summary?.cancelled_sessions || 0 }} 場
        </p>
      </article>
      <article class="card">
        <span class="eyebrow">出席狀況</span>
        <h2>{{ rate(report.summary?.attendance_rate) }}</h2>
        <p class="muted">
          已出席 {{ report.summary?.attendance_count || 0 }}／應出席
          {{ report.summary?.assigned_denominator || 0 }}
        </p>
      </article>
      <article class="card">
        <span class="eyebrow">人力異動</span>
        <h2>{{ report.summary?.leave_count || 0 }} 請假</h2>
        <p class="muted">
          代課 {{ report.summary?.replaced_count || 0 }} · 缺額
          {{ report.summary?.vacancies || 0 }}
        </p>
      </article>
      <article
        v-if="report.summary?.successful_test_donations_sum !== undefined"
        class="card"
      >
        <span class="eyebrow">模擬奉獻</span>
        <h2>{{ report.summary.successful_test_donations_sum || 0 }} 元</h2>
        <p class="muted">僅統計測試成功交易</p>
      </article>
      <article v-if="report.summary?.product_views !== undefined" class="card">
        <span class="eyebrow">展示瀏覽</span>
        <h2>{{ report.summary.product_views || 0 }} 次</h2>
        <p class="muted">愛心好食展示頁瀏覽</p>
      </article>
    </div>
    <div class="tablewrap" style="margin-top: 20px">
      <table class="table">
        <thead>
          <tr>
            <th>同工</th>
            <th>完成場次</th>
            <th>應出席</th>
            <th>已出席</th>
            <th>出席率</th>
            <th>請假</th>
            <th>代課</th>
            <th>時數</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in report.teachers || report.data || []"
            :key="r.teacher_id"
          >
            <td data-label="同工">{{ r.name }}</td>
            <td data-label="完成場次">{{ r.completed_sessions || 0 }}</td>
            <td data-label="應出席">{{ r.assigned || 0 }}</td>
            <td data-label="已出席">{{ r.attended || 0 }}</td>
            <td data-label="出席率">{{ rate(r.attendance_rate) }}</td>
            <td data-label="請假">{{ r.leave || 0 }}</td>
            <td data-label="代課">{{ r.replaced || 0 }}</td>
            <td data-label="時數">{{ r.hours || 0 }}</td>
          </tr>
          <tr v-if="!(report.teachers || report.data || []).length">
            <td colspan="8" class="empty">此區間沒有可呈現的服務資料。</td>
          </tr>
        </tbody>
      </table>
    </div></template
  >
</template>
