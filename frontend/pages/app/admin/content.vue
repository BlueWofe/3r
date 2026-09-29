<script setup lang="ts">
definePageMeta({ layout: "app" });
const fields = [
  {
    key: "kind",
    label: "類型",
    type: "select",
    options: ["page", "news", "product"],
  },
  { key: "title", label: "標題" },
  { key: "slug", label: "網址代稱" },
  { key: "summary", label: "摘要", type: "textarea" },
  { key: "body", label: "本文（純文字／安全標記）", type: "textarea" },
  {
    key: "status",
    label: "狀態",
    type: "select",
    options: ["draft", "published"],
  },
  { key: "category", label: "分類" },
];
</script>
<template>
  <EntityBoard
    title="內容管理"
    endpoint="/contents"
    :fields="fields"
    description="管理頁面、消息與展示品；公開頁僅呈現已發布的安全內容。"
  />
</template>
