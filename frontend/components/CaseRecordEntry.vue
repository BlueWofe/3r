<script setup lang="ts">
const cases = ref<any[]>([]), caseId = ref<number | null>(null)
const form = reactive({ service_date: new Date().toISOString().slice(0, 10), type: "關懷服務", summary: "", follow_up: "" })
const saved = ref(""); const { error, run } = useApiError()
onMounted(async()=>{try { cases.value=(await api<any>('/cases')).data||[] } catch(e:any) { error.value=e.message }})
async function save(){if(!caseId.value)return;await run(()=>api(`/cases/${caseId.value}/records`,{method:'POST',body:form}));saved.value='服務紀錄已新增';form.summary='';form.follow_up=''}
</script>
<template><section class="section" style="padding-bottom:0"><h2 class="serif">新增服務紀錄</h2><form class="card form" @submit.prevent="save"><label class="field">個案<select v-model="caseId" required><option :value="null">請選擇個案</option><option v-for="c in cases" :key="c.id" :value="c.id">{{c.code}} {{c.name}}</option></select></label><div class="grid responsive-two" style="grid-template-columns:1fr 1fr"><label class="field">服務日期<input v-model="form.service_date" type="date" required></label><label class="field">服務類型<input v-model="form.type" required></label></div><label class="field">服務摘要<textarea v-model="form.summary" required></textarea></label><label class="field">後續追蹤<textarea v-model="form.follow_up"></textarea></label><button class="button">儲存紀錄</button></form><p v-if="saved" class="notice">{{saved}}</p><p v-if="error" class="error">{{error}}</p></section></template>
