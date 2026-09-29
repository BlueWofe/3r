<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]),
  groups = ref<any[]>([]),
  groupId = ref(""),
  loading = ref(true),
  error = ref("");
const taipei = (value?: string) =>
  value
    ? new Intl.DateTimeFormat("zh-TW", {
        timeZone: "Asia/Taipei",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(value))
    : "發布時間未提供";
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [news, options] = await Promise.all([
      api<any>(
        `/group-news${groupId.value ? `?group_id=${groupId.value}` : ""}`,
      ),
      api<any>("/groups/options"),
    ]);
    rows.value = news.data || [];
    groups.value = options.data || [];
  } catch (e: any) {
    error.value = e.message || "目前無法取得小組消息。";
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">MY GROUPS</p>
      <h1>小組消息</h1>
      <p class="muted">僅顯示您目前所屬小組的已發布消息。</p>
    </div>
    <label class="field"
      >篩選小組<select v-model="groupId" @change="load">
        <option value="">全部小組</option>
        <option v-for="group in groups" :key="group.id" :value="group.id">
          {{ group.name }}
        </option>
      </select></label
    >
  </div>
  <div v-if="loading" class="empty">載入小組消息中…</div>
  <p v-else-if="error" class="notice">{{ error }}</p>
  <div v-else-if="!rows.length" class="card empty">
    目前沒有可閱讀的小組消息。
  </div>
  <div v-else class="grid cards">
    <NuxtLink
      v-for="item in rows"
      :key="item.id"
      class="card"
      :to="`/app/group-news/${item.id}`"
      ><span class="eyebrow">{{ item.group_names?.join("、") }}</span>
      <h3>{{ item.title }}</h3>
      <p class="muted">{{ item.summary }}</p>
      <small class="muted"
        >{{ taipei(item.published_at) }} ·
        {{ item.author_name || "協會編輯" }}</small
      ><small>閱讀消息 →</small></NuxtLink
    >
  </div>
</template>
