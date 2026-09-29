<script setup lang="ts">
definePageMeta({ layout: "app" });
const forms = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  responses = ref<any[]>([]),
  form = reactive<any>({
    title: "",
    description: "",
    status: "draft",
    deadline: "",
    fields: [] as any[],
  });
const fieldTypes = [
  "text",
  "textarea",
  "number",
  "date",
  "select",
  "multiselect",
  "file",
];
const { error, run } = useApiError();
async function load() {
  forms.value = (await api<any>("/forms")).data || [];
}
function edit(f?: any) {
  editing.value = f || null;
  Object.assign(form, {
    title: f?.title || "",
    description: f?.description || "",
    status: f?.status || "draft",
    deadline: f?.deadline || "",
    fields: structuredClone(f?.fields || []),
  });
  open.value = true;
}
function add() {
  form.fields.push({
    key: `field_${form.fields.length + 1}`,
    label: "新欄位",
    type: "text",
    required: false,
    options: [],
  });
}
async function save() {
  await run(() =>
    api(editing.value ? `/forms/${editing.value.id}` : "/forms", {
      method: editing.value ? "PUT" : "POST",
      body: form,
    }),
  );
  open.value = false;
  load();
}
async function seeResponses(f: any) {
  responses.value = (await api<any>(`/forms/${f.id}/responses`)).data || [];
  editing.value = f;
}
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">VERSIONED FORMS</p>
      <h1>表單中心</h1>
      <p class="muted">發布時由後端保存欄位快照；回覆以該版本呈現。</p>
    </div>
    <button class="button" @click="edit()">建立表單</button>
  </div>
  <div class="grid cards">
    <article v-for="f in forms" :key="f.id" class="card">
      <span :class="['status', f.status]">{{ f.status }}</span>
      <h3>{{ f.title }}</h3>
      <p class="muted">
        {{ f.description }}<br />{{ f.fields?.length || 0 }} 個欄位
      </p>
      <div class="actions">
        <button class="button ghost" @click="edit(f)">編輯欄位</button
        ><NuxtLink class="button gold" :to="`/app/forms/${f.id}`">填寫</NuxtLink
        ><button class="button" @click="seeResponses(f)">查看回覆</button
        ><a class="button ghost" :href="`/api/v1/forms/${f.id}/export`"
          >匯出 CSV</a
        >
      </div>
    </article>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>表單設計器</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <label class="field"
        >表單標題<input v-model="form.title" required /></label
      ><label class="field"
        >說明<textarea v-model="form.description"></textarea></label
      ><label class="field"
        >狀態<select v-model="form.status">
          <option>draft</option>
          <option>published</option>
        </select></label
      ><label class="field"
        >截止日<input v-model="form.deadline" type="date"
      /></label>
      <div class="card">
        <div class="workhead">
          <b>欄位</b
          ><button type="button" class="button ghost" @click="add">
            新增欄位
          </button>
        </div>
        <div
          v-for="(f, i) in form.fields"
          :key="i"
          class="grid"
          style="
            grid-template-columns: 1fr 1fr 120px 80px;
            align-items: end;
            margin: 12px 0;
          "
        >
          <label class="field">鍵值<input v-model="f.key" /></label
          ><label class="field">題目<input v-model="f.label" /></label
          ><label class="field"
            >種類<select v-model="f.type">
              <option v-for="t in fieldTypes" :key="t">{{ t }}</option>
            </select></label
          ><label
            v-if="f.type === 'select' || f.type === 'multiselect'"
            class="field"
            >選項（逗號分隔）<input
              :value="(f.options || []).join(',')"
              @input="
                f.options = ($event.target as HTMLInputElement).value
                  .split(',')
                  .filter(Boolean)
              " /></label
          ><label><input v-model="f.required" type="checkbox" /> 必填</label
          ><button
            type="button"
            class="button danger"
            @click="form.fields.splice(i, 1)"
          >
            移除
          </button>
        </div>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存版本</button>
    </form>
  </div>
  <div v-if="responses.length" class="modal">
    <div class="dialog">
      <div class="workhead">
        <h2>{{ editing?.title }} 的回覆</h2>
        <button class="button ghost" @click="responses = []">關閉</button>
      </div>
      <div
        v-for="r in responses"
        :key="r.id"
        class="card"
        style="margin-top: 8px"
      >
        <b>{{ r.created_at }}</b>
        <pre style="white-space: pre-wrap">{{
          JSON.stringify(r.answers, null, 2)
        }}</pre>
      </div>
    </div>
  </div>
</template>
