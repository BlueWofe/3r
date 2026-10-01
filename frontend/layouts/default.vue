<script setup lang="ts">
const { loggedIn, user, refresh, logout } = useAuth();
const navOpen = ref(false);
const navButton = ref<HTMLButtonElement | null>(null);
const { servicePath } = useWorkspaceNavigation();
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
  <div class="public-shell">
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
            <span class="footer-association-logo"
              ><img
                src="/images/association-backend-logo.png"
                alt="中華復甦更新發展協會標誌" /></span
            >{{ contact?.data?.association_name || "中華復甦更新發展協會" }}
          </div>
          <p>
            陪伴生命走過幽谷，在盼望中重新站立。
          </p>
        </div>
        <div>
          <b>關懷服務</b>
          <p>收容人關懷<br />更生陪伴<br />家庭支持</p>
        </div>
        <div>
          <b>聯絡方式</b>
          <p>
            服務專線：{{ contact?.data?.contact_phone || "02-0000-0000"
            }}<br />{{ contact?.data?.contact_email || "service@example.test"
            }}<br />{{ contact?.data?.address || "" }}
          </p>
        </div>
      </div>
    </footer>
  </div>
</template>
<style scoped>
.public-shell {
  background: #fffdf8;
  min-height: 100vh;
}
.public-shell :deep(.section) {
  padding-top: clamp(48px, 7vw, 88px);
  padding-bottom: clamp(48px, 7vw, 88px);
}
.public-shell :deep(.pagehead) {
  padding-top: 56px;
  padding-bottom: 48px;
  background: #f3f4ee;
}
.public-shell :deep(.pagehead h1) {
  font-size: clamp(30px, 4vw, 48px);
}
.public-shell :deep(.pagehead .muted) {
  margin-bottom: 0;
}
.brand {
  white-space: normal;
  min-width: 0;
}
.brand span {
  min-width: 0;
}
.links {
  gap: 14px;
  font-size: 14px;
}
.links .button {
  padding: 10px 16px;
}
.footer .brand {
  line-height: 1.5;
}
.footer-association-logo {
  flex-shrink: 0;
}
a:focus-visible,
button:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
.links {
  flex-wrap: wrap;
}
.footer-association-logo {
  position: relative;
  display: inline-block;
  width: 88px;
  height: 88px;
  margin-right: 10px;
  overflow: hidden;
  vertical-align: middle;
  border-radius: 10px;
  background: #f7f0df;
}
.footer-association-logo img {
  position: absolute;
  width: 136px;
  height: 170px;
  max-width: none;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}
@media (max-width: 1100px) {
  .mobile-menu {
    display: inline-flex;
    min-height: 44px;
    flex-shrink: 0;
  }
  .links {
    display: none;
  }
  .links.open {
    display: flex;
    position: absolute;
    top: 76px;
    left: 0;
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    padding: 20px;
    background: #fffdf8;
    border-bottom: 1px solid var(--line);
    box-shadow: 0 12px 30px #14312810;
  }
  .links.open {
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
  }
  .links.open a,
  .links.open button {
    min-height: 44px;
  }
}
@media (max-width: 400px) {
  .navin .brand {
    font-size: 14px;
    gap: 8px;
  }
  .navin .brand :deep(img) {
    width: 42px;
    height: 42px;
  }
}
</style>
