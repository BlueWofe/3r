<script setup lang="ts">
definePageMeta({ layout: "app" });
const templates = ref<any[]>([]),
  teachers = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  preview = ref<any>(null),
  result = ref<any>(null),
  error = ref("");
const taiwanToday = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Taipei",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
})
  .format(new Date())
  .replaceAll("/", "-");
const blank = () => ({
  name: "",
  prison: "",
  location: "",
  participant_count: 0,
  teacher_ids: [],
  active: true,
  start_date: taiwanToday,
  end_date: null,
  version: undefined,
  rules: [
    {
      id: crypto.randomUUID(),
      frequency: "weekly",
      weekdays: [1],
      start_time: "09:00",
      end_time: "11:00",
    },
  ],
});
const form = reactive<any>(blank());
async function load() {
  try {
    templates.value = (await api<any>("/class-templates")).data || [];
    teachers.value = (await api<any>("/teachers")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
}
function edit(t?: any) {
  editing.value = t || null;
  Object.assign(form, structuredClone(t || blank()));
  open.value = true;
}
function rule() {
  form.rules.push({
    id: crypto.randomUUID(),
    frequency: "weekly",
    weekdays: [1],
    start_time: "09:00",
    end_time: "11:00",
  });
}
async function save() {
  try {
    await api(
      editing.value
        ? `/class-templates/${editing.value.id}`
        : "/class-templates",
      { method: editing.value ? "PUT" : "POST", body: form },
    );
    open.value = false;
    load();
  } catch (e: any) {
    error.value = e.message;
  }
}
async function seePreview() {
  try {
    preview.value = await api<any>("/class-templates/preview", {
      method: "POST",
      body: form,
    });
  } catch (e: any) {
    error.value = e.message;
  }
}
async function generate(t: any) {
  try {
    result.value = await api(`/class-templates/${t.id}/generate`, {
      method: "POST",
      body: { version: t.version },
    });
    load();
  } catch (e: any) {
    error.value = e.message;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">CLASS TEMPLATES</p>
      <h1>班別管理</h1>
      <p class="muted">
        設定規律後預覽未來 90
        天，確認衝突與略過項目再生成服務場次；系統每天台灣時間 00:10
        自動補齊未來場次，修改只影響尚未生成者，既有手動異動會保留。
      </p>
    </div>
    <button class="button" @click="edit()">新增班別</button>
  </div>
  <div v-if="error" class="notice">{{ error }}</div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>班別</th>
          <th>監所／地點</th>
          <th>同工</th>
          <th>規則</th>
          <th>狀態</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in templates" :key="t.id">
          <td>{{ t.name }}</td>
          <td>{{ t.prison }}／{{ t.location }}</td>
          <td>{{ t.teacher_ids?.length || 0 }} 位</td>
          <td>{{ t.rules?.length || 0 }} 組</td>
          <td>{{ t.active ? "啟用" : "停用" }}</td>
          <td>
            <button class="button ghost" @click="edit(t)">編輯／預覽</button>
            <button class="button" @click="generate(t)">生成場次</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "編輯" : "新增" }}班別</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field"
          >班別名稱<input v-model="form.name" required /></label
        ><label class="field"
          >監所<input v-model="form.prison" required /></label
        ><label class="field"
          >地點<input v-model="form.location" required /></label
        ><label class="field"
          >參與人數<input
            v-model.number="form.participant_count"
            type="number"
            min="0" /></label
        ><label class="field"
          >開始日<input v-model="form.start_date" type="date" required /></label
        ><label class="field"
          >結束日<input v-model="form.end_date" type="date"
        /></label>
      </div>
      <label class="field"
        >預設同工<select v-model="form.teacher_ids" multiple>
          <option v-for="t in teachers" :key="t.id" :value="t.id">
            {{ t.name }}
          </option>
        </select></label
      ><label
        ><input v-model="form.active" type="checkbox" /> 啟用自動生成</label
      >
      <div class="card">
        <div class="workhead">
          <h3>時段規則</h3>
          <button type="button" class="button ghost" @click="rule">
            新增規則
          </button>
        </div>
        <article v-for="(r, i) in form.rules" :key="r.id" class="card">
          <div
            class="grid responsive-two"
            style="grid-template-columns: 1fr 1fr"
          >
            <label class="field"
              >頻率<select v-model="r.frequency">
                <option value="weekly">每週</option>
                <option value="monthly_date">每月日期</option>
                <option value="monthly_weekday">每月第幾週</option>
              </select></label
            ><label v-if="r.frequency === 'weekly'" class="field"
              >星期（可複選）<select v-model="r.weekdays" multiple>
                <option v-for="d in 7" :key="d" :value="d">
                  週{{ ["一", "二", "三", "四", "五", "六", "日"][d - 1] }}
                </option>
              </select></label
            ><label v-if="r.frequency === 'monthly_date'" class="field"
              >每月日期<input
                v-model.number="r.month_day"
                type="number"
                min="1"
                max="31" /></label
            ><label v-if="r.frequency === 'monthly_weekday'" class="field"
              >第幾週<select v-model.number="r.week_of_month">
                <option v-for="n in 5" :key="n" :value="n">
                  第 {{ n }} 週
                </option>
                <option :value="-1">最後一週</option>
              </select></label
            ><label v-if="r.frequency === 'monthly_weekday'" class="field"
              >星期<select v-model.number="r.weekday">
                <option v-for="d in 7" :key="d" :value="d">
                  週{{ ["一", "二", "三", "四", "五", "六", "日"][d - 1] }}
                </option>
              </select></label
            ><label class="field"
              >開始<input v-model="r.start_time" type="time" /></label
            ><label class="field"
              >結束<input v-model="r.end_time" type="time"
            /></label>
          </div>
          <button
            type="button"
            class="button danger"
            @click="form.rules.splice(i, 1)"
          >
            移除此規則
          </button>
        </article>
      </div>
      <div class="actions">
        <button type="button" class="button ghost" @click="seePreview">
          預覽 90 天</button
        ><button class="button">儲存班別</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <div v-if="preview" class="notice">
        預覽 {{ preview.data?.length || 0 }} 場，略過
        {{ preview.skipped?.length || 0 }} 項，至 {{ preview.through }}。
      </div>
    </form>
  </div>
  <div v-if="result" class="modal">
    <div class="dialog">
      <h2>生成結果</h2>
      <p class="muted">
        新增 {{ result.created }} 場、既有 {{ result.existing }} 場、略過
        {{ result.skipped?.length || 0 }} 場，至 {{ result.through }}。
      </p>
      <pre>{{ JSON.stringify(result.skipped, null, 2) }}</pre>
      <button class="button" @click="result = null">關閉</button>
    </div>
  </div>
</template>
