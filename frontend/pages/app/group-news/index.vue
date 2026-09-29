<script setup lang="ts">
definePageMeta({ layout: "app" });
const { data, error } = await useAsyncData("group-news", () =>
  api<any>("/group-news"),
);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">MY GROUPS</p>
      <h1>小組消息</h1>
    </div>
  </div>
  <p v-if="error" class="notice">目前無法取得小組消息。</p>
  <div class="grid cards">
    <NuxtLink
      v-for="item in data?.data"
      :key="item.id"
      class="card"
      :to="`/app/group-news/${item.id}`"
      ><span class="eyebrow">{{ item.group_names?.join("、") }}</span>
      <h3>{{ item.title }}</h3>
      <p class="muted">{{ item.summary }}</p>
      <small>閱讀消息 →</small></NuxtLink
    >
  </div>
</template>
