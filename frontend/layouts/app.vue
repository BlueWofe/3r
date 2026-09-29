<script setup lang="ts">
const { user, logout, refresh, can } = useAuth();
const { schedule, managementLinks } = useWorkspaceNavigation();
const ready = ref(false);
const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);
const route = useRoute();
watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false;
  },
);
function closeMenu(restoreFocus = false) {
  menuOpen.value = false;
  if (restoreFocus) menuButton.value?.focus();
}
onMounted(async () => {
  await refresh();
  if (!user.value) await navigateTo("/login");
  else ready.value = true;
});
</script>
<template>
  <div class="app-shell">
    <header class="workspace-mobile-header">
      <NuxtLink class="public-return" to="/">← 回到官網</NuxtLink>
      <button
        ref="menuButton"
        class="button ghost"
        type="button"
        aria-controls="workspace-navigation"
        :aria-expanded="menuOpen"
        :aria-label="menuOpen ? '關閉工作台選單' : '開啟工作台選單'"
        @click="menuOpen = !menuOpen"
        @keydown.esc.prevent="closeMenu(true)"
      >
        ☰ 工作台選單
      </button>
    </header>
    <aside
      :class="['side', { 'menu-open': menuOpen }]"
      @keydown.esc.prevent="closeMenu(true)"
    >
      <NuxtLink class="brand" to="/"
        ><span class="seal">✦</span>復甦更新</NuxtLink
      >
      <NuxtLink class="public-return desktop-return" to="/"
        >← 回到官網</NuxtLink
      >
      <nav
        id="workspace-navigation"
        aria-label="工作台導覽"
        @click="closeMenu()"
      >
        <div class="group">我的服務</div>
        <NuxtLink v-if="schedule()" to="/app">今日行程</NuxtLink
        ><NuxtLink v-if="schedule()" to="/app/calendar">行事曆</NuxtLink
        ><NuxtLink v-if="schedule()" to="/app/changes">異動通知</NuxtLink
        ><NuxtLink v-if="schedule()" to="/app/invitations">邀請與通知</NuxtLink
        ><NuxtLink
          v-if="can('resources.read.own') || can('resources.read.all')"
          to="/app/resources"
          >資源下載</NuxtLink
        ><NuxtLink
          v-if="can('forms.read.own') || can('forms.read.all')"
          to="/app/forms"
          >我的表單</NuxtLink
        ><NuxtLink to="/app/profile">個人資料與奉獻</NuxtLink>
        <div v-if="managementLinks.length" class="group">管理工作台</div>
        <NuxtLink v-for="l in managementLinks" :key="l[0]" :to="l[0]">{{
          l[1]
        }}</NuxtLink
        ><button class="button ghost" style="margin: 18px 10px" @click="logout">
          登出
        </button>
      </nav>
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

<style scoped>
.side {
  overflow-y: auto;
}
.workspace-mobile-header {
  display: none;
}
.public-return {
  font-weight: 700;
}
.side .desktop-return {
  margin-top: 12px;
  border: 1px solid #ffffff55;
}
a:focus-visible,
button:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
@media (max-width: 760px) {
  .workspace-mobile-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    background: var(--pine);
    color: #fff;
    position: sticky;
    top: 0;
    z-index: 40;
  }
  .workspace-mobile-header .button {
    color: #fff;
    border-color: #ffffff66;
    padding: 8px 12px;
  }
  .side {
    display: none;
    position: static;
    height: auto;
    padding: 12px 16px;
    overflow: visible;
    white-space: normal;
  }
  .side.menu-open {
    display: block;
  }
  .side .brand {
    display: flex;
  }
  .side .group {
    display: block;
  }
  .side nav {
    display: grid;
    gap: 4px;
  }
  .side a {
    min-height: 44px;
    display: flex;
    align-items: center;
  }
  .side .desktop-return {
    display: none;
  }
}
</style>
