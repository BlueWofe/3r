<script setup lang="ts">
const { user, logout, refresh, can } = useAuth();
const ready = ref(false);
onMounted(async () => {
  await refresh();
  if (!user.value) await navigateTo("/login");
  else ready.value = true;
});
const schedule = () => can("schedule.read.own") || can("schedule.read.all");
const adminLinks = [
  [
    "/app/admin/schedule",
    "排程管理",
    () => can("schedule.create.all") || can("schedule.update.all"),
  ],
  [
    "/app/admin/users",
    "人員與角色",
    () => can("users.read.all") || can("users.update.all"),
  ],
  ["/app/admin/roles", "角色權限", () => can("roles.manage.all")],
  [
    "/app/admin/content",
    "內容管理",
    () => can("content.read.all") || can("content.update.all"),
  ],
  [
    "/app/admin/cases",
    "個案紀錄",
    () => can("cases.read.all") || can("cases.read.assigned"),
  ],
  [
    "/app/admin/meetings",
    "會議管理",
    () => can("meetings.read.all") || can("meetings.read.own"),
  ],
  [
    "/app/admin/forms",
    "表單中心",
    () => can("forms.read.all") || can("forms.read.own"),
  ],
  [
    "/app/admin/reports",
    "服務報表",
    () => can("reports.read.all") || can("reports.read.own"),
  ],
  ["/app/admin/settings", "協會設定", () => can("settings.manage.all")],
] as const;
</script>
<template>
  <div class="app-shell">
    <aside class="side">
      <NuxtLink class="brand" to="/"
        ><span class="seal">✦</span>復甦更新</NuxtLink
      >
      <div class="group">我的服務</div>
      <NuxtLink v-if="schedule()" to="/app">今日行程</NuxtLink
      ><NuxtLink v-if="schedule()" to="/app/calendar">行事曆</NuxtLink
      ><NuxtLink v-if="schedule()" to="/app/changes">異動通知</NuxtLink
      ><NuxtLink to="/app/invitations">邀請與通知</NuxtLink
      ><NuxtLink to="/app/resources">資源下載</NuxtLink
      ><NuxtLink to="/app/forms">我的表單</NuxtLink
      ><NuxtLink to="/app/profile">個人資料與奉獻</NuxtLink>
      <div v-if="adminLinks.some((x) => x[2]())" class="group">管理工作台</div>
      <NuxtLink
        v-for="l in adminLinks.filter((x) => x[2]())"
        :key="l[0]"
        :to="l[0]"
        >{{ l[1] }}</NuxtLink
      ><button class="button ghost" style="margin: 18px 10px" @click="logout">
        登出
      </button>
    </aside>
    <main v-if="ready" class="workspace">
      <div class="notice">
        <span class="demo">示範模式</span>
        所有通知、金流及雲端操作皆為測試模擬，資料以權限及版本控制保護。
      </div>
      <slot />
    </main>
  </div>
</template>
