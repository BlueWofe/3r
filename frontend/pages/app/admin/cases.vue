<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const users = ref<any[]>([]),
  error = ref("");
const canAssign = () =>
  can("cases.create.all") && can("cases.update.all") && can("users.read.all");
const canCreate = () => can("cases.create.all");
const canUpdate = () => can("cases.update.all") || can("cases.update.assigned");
const fields = computed(() => [
  { key: "code", label: "個案代碼" },
  { key: "name", label: "姓名" },
  { key: "status", label: "狀態" },
  { key: "prison", label: "監所", optional: true },
  { key: "contact", label: "聯絡方式", optional: true },
  {
    key: "assigned_user_id",
    label: "負責同工",
    type: "select",
    options: users.value,
    optional: true,
    readonly: !canAssign(),
  },
]);
onMounted(async () => {
  if (!canAssign()) return;
  try {
    users.value = (await api<any>("/users")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
});
</script>
<template>
  <EntityBoard
    title="個案紀錄"
    endpoint="/cases"
    :fields="fields"
    :can-create="canCreate()"
    :can-update="canUpdate()"
    description="個案資料依權限顯示；請避免在摘要中放入敏感資訊。"
  />
  <p v-if="error" class="notice">
    負責同工選單暫時無法取得，既有指派仍會保留。
  </p>
  <CaseRecordEntry v-if="canUpdate()" />
</template>
