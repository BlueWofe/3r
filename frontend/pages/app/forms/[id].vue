<script setup lang="ts">
definePageMeta({ layout: "app" });
const route = useRoute();
const form = ref<any>(null),
  answers = reactive<any>({}),
  files = reactive<Record<string, File | null>>({}),
  done = ref(false);
const { error, run } = useApiError();
onMounted(async () => {
  form.value = await api(`/forms/${route.params.id}`);
});
async function upload(key: string) {
  const file = files[key];
  if (!file) return null;
  const fd = new FormData();
  fd.append("file", file);
  fd.append("form_id", String(route.params.id));
  fd.append("field_key", key);
  fd.append("visibility", "private");
  const r: any = await run(() => api("/files", { method: "POST", body: fd }));
  return r.id;
}
async function submit() {
  const payload: any = { ...answers };
  for (const f of form.value.fields || [])
    if (f.type === "file") payload[f.key] = await upload(f.key);
  await run(() =>
    api(`/forms/${route.params.id}/responses`, {
      method: "POST",
      body: { answers: payload },
    }),
  );
  done.value = true;
}
</script>
<template>
  <WorkspaceHero :title="form?.title || '載入表單'" :subtitle="form?.description" eyebrow="我的表單" light><template #actions><NuxtLink class="button ghost" to="/app/forms">返回我的表單</NuxtLink></template><template #summary><h3>填寫指引</h3><p>請確認必填欄位已完整填寫；附件與資料依表單權限保存。</p><dl v-if="form"><div v-if="form.version"><dt>版本</dt><dd>{{ form.version }}</dd></div><div v-if="form.deadline"><dt>截止日期</dt><dd>{{ form.deadline }}</dd></div><div><dt>必填欄位</dt><dd>{{ form.fields?.filter((field: any) => field.required).length || 0 }} 項</dd></div></dl></template></WorkspaceHero>
  <form
    v-if="form && !done"
    class="card form"
    @submit.prevent="submit"
  >
    <p class="muted">請依序填寫下列欄位；標示必填的項目需完成後才能送出。</p>
    <label v-for="f in form.fields" :key="f.key" class="field"
      >{{ f.label }} <small v-if="f.required">（必填）</small
      ><textarea
        v-if="f.type === 'textarea'"
        v-model="answers[f.key]"
        :required="f.required"
      ></textarea
      ><select
        v-else-if="f.type === 'select' || f.type === 'multiselect'"
        v-model="answers[f.key]"
        :multiple="f.type === 'multiselect'"
        :required="f.required"
      >
        <option v-for="o in f.options" :key="o" :value="o">
          {{ o }}
        </option></select
      ><input
        v-else-if="f.type === 'file'"
        type="file"
        :required="f.required"
        @change="
          files[f.key] = ($event.target as HTMLInputElement).files?.[0] || null
        " /><input
        v-else
        v-model="answers[f.key]"
        :type="
          f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'
        "
        :required="f.required"
    /></label>
    <p v-if="error" class="error">{{ error }}</p>
    <button class="button">送出表單</button>
  </form>
  <div v-if="done" class="notice" role="status">
    已完成送出。上傳檔案將依表單權限以私有方式保存。
  </div>
</template>
