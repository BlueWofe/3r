<script setup lang="ts">
const { loggedIn, user, refresh, logout } = useAuth();
onMounted(refresh);
</script>
<template>
  <div class="nav">
    <div class="container navin">
      <NuxtLink class="brand" to="/"
        ><span class="seal">✦</span
        ><span>中華復甦更新<br />發展協會</span></NuxtLink
      >
      <nav class="links">
        <NuxtLink to="/about">關於我們</NuxtLink
        ><NuxtLink to="/news">最新消息</NuxtLink
        ><NuxtLink to="/food">愛心好食</NuxtLink
        ><NuxtLink to="/contact">聯絡我們</NuxtLink
        ><NuxtLink to="/search">搜尋</NuxtLink
        ><NuxtLink class="button gold" to="/donate">支持事工</NuxtLink
        ><NuxtLink v-if="!loggedIn" class="button" to="/login"
          >會員登入</NuxtLink
        ><NuxtLink v-else class="button" to="/app"
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
        <div class="brand"><span class="seal">✦</span>中華復甦更新發展協會</div>
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
        <p>服務專線：02-0000-0000<br />service@example.test</p>
      </div>
    </div>
  </footer>
</template>
