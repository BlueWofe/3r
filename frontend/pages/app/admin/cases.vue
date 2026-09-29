<script setup lang="ts">
definePageMeta({ layout: "app" });
const fields = [
  { key: "code", label: "個案代碼" },
  { key: "name", label: "姓名" },
  { key: "status", label: "狀態" },
  { key: "prison", label: "監所" },
  { key: "contact", label: "聯絡方式" },
];
</script>
<template>
  <EntityBoard
    title="個案紀錄"
    endpoint="/cases"
    :fields="fields"
    description="個案資料依權限顯示；請避免在摘要中放入敏感資訊。"
  />
</template>
