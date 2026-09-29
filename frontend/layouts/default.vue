<script setup lang="ts">
const { loggedIn, user, refresh, logout } = useAuth();
const navOpen = ref(false);
const navButton = ref<HTMLButtonElement | null>(null);
const { servicePath, managementPath } = useWorkspaceNavigation();
const route = useRoute();
watch(
  () => route.fullPath,
  () => {
    navOpen.value = false;
  },
);
function closeNavigation() {
  navOpen.value = false;
  navButton.value?.focus();
}
const { data: contact } = await useAsyncData("public-contact", () =>
  api<any>("/public/contact").catch(() => ({ data: null })),
);
onMounted(refresh);
</script>
<template>
  <div class="nav">
    <div class="container navin">
      <NuxtLink class="brand" to="/"
        ><BrandLogo :logo-url="contact?.data?.logo_url" /><span>{{
          contact?.data?.association_name || "中華復甦更新發展協會"
        }}</span></NuxtLink
      >
      <button
        ref="navButton"
        class="mobile-menu button ghost"
        :aria-expanded="navOpen"
        aria-controls="public-navigation"
        :aria-label="navOpen ? '關閉導覽選單' : '開啟導覽選單'"
        @click="navOpen = !navOpen"
        @keydown.esc.prevent="closeNavigation"
      >
        ☰
      </button>
      <nav
        id="public-navigation"
        aria-label="官網導覽"
        :class="['links', { open: navOpen }]"
        @click="navOpen = false"
        @keydown.esc.prevent="closeNavigation"
      >
        <NuxtLink to="/about">關於我們</NuxtLink
        ><NuxtLink to="/news">最新消息</NuxtLink
        ><NuxtLink to="/food">愛心好食</NuxtLink
        ><NuxtLink to="/contact">聯絡我們</NuxtLink
        ><NuxtLink to="/search">搜尋</NuxtLink
        ><NuxtLink class="button gold" to="/donate">支持事工</NuxtLink
        ><NuxtLink v-if="!loggedIn" class="button" to="/login"
          >會員登入</NuxtLink
        ><NuxtLink v-else class="button" :to="servicePath"
          >{{ user?.name }} 的工作台</NuxtLink
        ><NuxtLink
          v-if="loggedIn && managementPath"
          class="button ghost"
          :to="managementPath"
          >管理工作台</NuxtLink
        ><button v-if="loggedIn" class="button ghost" @click="logout">
          登出
        </button>
      </nav>
    </div>
  </div>
  <slot />
  <footer class="footer">
    <div class="container grid">
      <div>
        <div class="brand">
          <BrandLogo :logo-url="contact?.data?.logo_url" footer />{{
            contact?.data?.association_name || "中華復甦更新發展協會"
          }}
        </div>
        <p>
          陪伴生命走過幽谷，在盼望中重新站立。<br /><span class="demo"
            >示範網站・所有內容均為虛構 UAT 資料</span
          >
        </p>
      </div>
      <div>
        <b>關懷服務</b>
        <p>收容人關懷<br />更生陪伴<br />家庭支持</p>
      </div>
      <div>
        <b>聯絡方式</b>
        <p>
          服務專線：{{ contact?.data?.contact_phone || "02-0000-0000" }}<br />{{
            contact?.data?.contact_email || "service@example.test"
          }}<br />{{ contact?.data?.address || "" }}
        </p>
      </div>
    </div>
  </footer>
</template>
<style scoped>
a:focus-visible,
button:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
.links {
  flex-wrap: wrap;
}
@media (max-width: 760px) {
  .links.open {
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
  }
  .links.open a,
  .links.open button {
    min-height: 44px;
  }
}
</style>
