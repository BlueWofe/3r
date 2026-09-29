<script setup lang="ts">
definePageMeta({ layout: "app" });
const fields = [
  {
    key: "kind",
    label: "類型",
    type: "select",
    options: ["page", "news"],
  },
  { key: "title", label: "標題" },
  { key: "slug", label: "網址代稱" },
  { key: "summary", label: "摘要", type: "textarea", optional: true },
  { key: "body", label: "本文（純文字／安全標記）", type: "textarea" },
  {
    key: "status",
    label: "狀態",
    type: "select",
    options: ["draft", "published"],
  },
  { key: "category", label: "分類", optional: true },
  {
    key: "sort_order",
    label: "排序（數字愈小愈前）",
    type: "number",
    optional: true,
  },
  { key: "image_id", label: "公開圖片", type: "file", optional: true },
];
</script>
<template>
  <EntityBoard
    title="內容管理"
    endpoint="/contents"
    :fields="fields"
    :exclude-kinds="['product']"
    description="管理協會頁面與消息；食品請至產品管理設定規格與價格。"
  />
</template>
