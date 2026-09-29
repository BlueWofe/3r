<script setup lang="ts">
const props = defineProps<{ cases: any[] }>();
const caseId = ref<number | null>(null),
  records = ref<any[]>([]),
  saved = ref("");
const form = reactive({
  service_date: new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Taipei",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  })
    .format(new Date())
    .replaceAll("/", "-"),
  type: "關懷服務",
  summary: "",
  follow_up: "",
});
const { error, run } = useApiError();
async function loadRecords() {
  if (!caseId.value) {
    records.value = [];
    return;
  }
  try {
    const response = await api<any>(`/cases/${caseId.value}`);
    records.value = response.data?.records || response.records || [];
  } catch (caught: any) {
    error.value = caught.message;
  }
}
watch(caseId, loadRecords);
watch(
  () => props.cases,
  (rows) => {
    if (caseId.value && !rows.some((row) => row.id === caseId.value))
      caseId.value = null;
  },
  { deep: true },
);
async function save() {
  if (!caseId.value) return;
  await run(() =>
    api(`/cases/${caseId.value}/records`, { method: "POST", body: form }),
  );
  await loadRecords();
  saved.value = "服務紀錄已更新";
  form.summary = "";
  form.follow_up = "";
}
</script>
<template>
  <section class="section" style="padding-bottom: 0">
    <h2 class="serif">服務紀錄</h2>
    <form class="card form" @submit.prevent="save">
      <label class="field"
        >個案<select v-model="caseId" required>
          <option :value="null">請選擇個案</option>
          <option v-for="c in cases" :key="c.id" :value="c.id">
            {{ c.code }} {{ c.name }}
          </option>
        </select></label
      >
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field"
          >服務日期<input
            v-model="form.service_date"
            type="date"
            required /></label
        ><label class="field"
          >服務類型<input v-model="form.type" required
        /></label>
      </div>
      <label class="field"
        >服務摘要<textarea v-model="form.summary" required /></label
      ><label class="field">後續追蹤<textarea v-model="form.follow_up" /></label
      ><button class="button">儲存紀錄</button>
    </form>
    <p v-if="saved" class="notice">{{ saved }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <ol v-if="caseId && records.length" class="story-timeline">
      <li v-for="record in records" :key="record.id">
        <b>{{ record.service_date }}｜{{ record.type }}</b
        ><br />{{ record.summary
        }}<small class="muted"
          >{{ record.author_name || "服務同工" }} ·
          {{ record.created_at || "" }}</small
        >
      </li>
    </ol>
    <p v-else-if="caseId" class="muted">此個案尚無服務紀錄。</p>
  </section>
</template>
