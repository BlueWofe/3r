<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const fields = [
  { key: "name", label: "監所名稱" },
  { key: "address", label: "監所地址", type: "textarea", optional: true },
  {
    key: "active",
    label: "狀態",
    type: "select",
    options: [
      { id: true, name: "啟用" },
      { id: false, name: "停用" },
    ],
    defaultValue: true,
  },
];
</script>
<template>
  <EntityBoard
    title="監所管理"
    endpoint="/prisons"
    :fields="fields"
    :can-create="can('prisons.manage.all')"
    :can-update="can('prisons.manage.all')"
    description="可新增或編輯監所名稱與地址；課程的教室、樓層等上課位置另於排課設定。停用監所保留既有關聯。"
  />
</template>
