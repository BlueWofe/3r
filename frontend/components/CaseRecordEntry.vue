<script setup lang="ts">
const props = defineProps<{ cases: any[]; canWrite: boolean }>();
const route = useRoute();
const router = useRouter();
const pending = ref(false);
const queryCaseId = () => {
  const value = Number(route.query.case_id);
  return Number.isInteger(value) && value > 0 ? value : null;
};
const caseId = ref<number | null>(queryCaseId()),
  records = ref<any[]>([]),
  saved = ref(""),
  loading = ref(false);
let request = 0;
const taipeiTime = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(value))
    : "";
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
  const id = caseId.value;
  const sequence = ++request;
  records.value = [];
  error.value = "";
  loading.value = false;
  if (!id) return;
  loading.value = true;
  try {
    const response = await api<any>(`/cases/${id}`);
    if (sequence === request && id === caseId.value) {
      const detail = response.data?.data || response.data || response;
      records.value = Array.isArray(detail.records) ? detail.records : [];
    }
  } catch (caught: any) {
    if (sequence === request) error.value = caught.message;
  } finally {
    if (sequence === request) loading.value = false;
  }
}
watch(caseId, async (id) => {
  saved.value = "";
  records.value = [];
  const next = id ? String(id) : undefined;
  if (route.query.case_id !== next)
    await router.replace({ query: { ...route.query, case_id: next } });
  loadRecords();
});
watch(
  () => route.query.case_id,
  (value) => {
    const next = Number(value);
    const valid = Number.isInteger(next) && next > 0 ? next : null;
    if (caseId.value !== valid) caseId.value = valid;
  },
);
watch(
  () => props.cases,
  (rows) => {
    if (!rows.length) return;
    if (
      caseId.value &&
      !rows.some((row) => Number(row.id) === Number(caseId.value))
    )
      caseId.value = null;
    else if (caseId.value) loadRecords();
  },
  { deep: true, immediate: true },
);
async function save() {
  if (!caseId.value || pending.value) return;
  pending.value = true;
  try {
    await run(() =>
      api(`/cases/${caseId.value}/records`, { method: "POST", body: form }),
    );
    await loadRecords();
    saved.value = "已更新";
    form.summary = "";
    form.follow_up = "";
  } finally {
    pending.value = false;
  }
}
</script>
<template>
  <section class="section" style="padding-bottom: 0">
    <h2 class="serif">服務紀錄</h2>
    <label class="field"
      >個案<select v-model="caseId" :disabled="pending" required>
        <option :value="null">請選擇個案</option>
        <option v-for="c in cases" :key="c.id" :value="c.id">
          {{ c.code }} {{ c.name }}
        </option>
      </select></label
    >
    <form v-if="canWrite" class="card form" @submit.prevent="save">
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
      ><button class="button" :disabled="pending || !caseId">
        {{ pending ? "儲存中…" : "儲存紀錄" }}
      </button>
    </form>
    <p v-if="saved" class="notice">{{ saved }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading" class="muted">載入服務紀錄中…</p>
    <ol v-else-if="caseId && records.length" class="story-timeline">
      <li v-for="(record, index) in records" :key="record.id || index">
        <b>{{ record.service_date }}｜{{ record.type }}</b
        ><br />
        {{ record.summary }}
        <p v-if="record.follow_up" class="muted">
          後續追蹤：{{ record.follow_up }}
        </p>
        <small class="muted"
          >{{ record.author_name || "服務同工" }} ·
          {{ taipeiTime(record.created_at) }}</small
        >
      </li>
    </ol>
    <p v-else-if="caseId" class="muted">此個案尚無服務紀錄。</p>
  </section>
</template>
