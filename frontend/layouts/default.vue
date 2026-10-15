<script setup lang="ts">
const { loggedIn, user, refresh, logout } = useAuth();
const cart = useShoppingCart();
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
          ><BrandLogo
            :logo-url="
              contact?.data?.logo_url || '/images/redesign/imgBrandLogo.png'
            "
          /><span>{{
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
          選單
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
          ><NuxtLink to="/cart"
            >購物車<span v-if="cart.count.value"
              >（{{ cart.count.value }}）</span
            ></NuxtLink
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
    <main id="public-content"><slot /></main>
    <footer class="footer">
      <div class="container footer-grid">
        <div>
          <NuxtLink class="brand" to="/"
            ><img
              :src="
                contact?.data?.logo_url || '/images/redesign/imgBrandLogo.png'
              "
              alt="協會標誌"
              loading="lazy"
            /><span>{{
              contact?.data?.association_name || "中華復甦更新發展協會"
            }}</span></NuxtLink
          >
          <p>
            協助監獄更生人及發展監獄事工。<br />陪伴生命走過幽谷，在盼望中重新站立。
          </p>
        </div>
        <div class="footer-column">
          <b>關於協會</b><NuxtLink to="/about#mission">協會宗旨</NuxtLink
          ><NuxtLink to="/about#history">協會沿革</NuxtLink
          ><NuxtLink to="/about#organization">組織與同工</NuxtLink
          ><NuxtLink to="/news">最新消息</NuxtLink>
        </div>
        <div class="footer-column">
          <b>同行支持</b><NuxtLink to="/food">愛心好食</NuxtLink
          ><NuxtLink to="/contact">與我們聯絡</NuxtLink
          ><NuxtLink to="/donate">支持事工</NuxtLink>
        </div>
        <div class="footer-column">
          <b>聯絡方式</b
          ><a
            v-if="contact?.data?.contact_phone"
            :href="`tel:${contact.data.contact_phone}`"
            >{{ contact.data.contact_phone }}</a
          ><a
            v-if="contact?.data?.contact_email"
            :href="`mailto:${contact.data.contact_email}`"
            >{{ contact.data.contact_email }}</a
          >
          <p v-if="contact?.data?.address">{{ contact.data.address }}</p>
        </div>
      </div>
      <div class="footer-bottom">
        <div class="container">
          <span
            >© {{ new Date().getFullYear() }}
            {{ contact?.data?.association_name || "中華復甦更新發展協會" }} ·
            版權所有</span
          ><span>盼望 · 尊嚴 · 同行 · 更新</span>
        </div>
      </div>
    </footer>
  </div>
</template>
