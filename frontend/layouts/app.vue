<script setup lang="ts">
const { user, logout, can } = useAuth();
const { associationName, logoUrl } = useAssociationBranding();
const { schedule, managementLinks, managementGroups } =
  useWorkspaceNavigation();
const ready = computed(() => !!user.value && !user.value.must_change_password);
const openMenu = ref<"service" | "management" | null>(null);
const serviceButton = ref<HTMLButtonElement | null>(null);
const managementButton = ref<HTMLButtonElement | null>(null);
const route = useRoute();
const breadcrumb = computed(() => managementLinks.value.find(link => activeLink(link[0]))?.[1] || serviceLinks.value.find(link => link[0] === route.path)?.[1] || (route.path.startsWith('/app/forms/') ? '填寫表單' : route.path.startsWith('/app/group-news/') ? '小組消息' : '我的服務'));

const serviceLinks = computed(() =>
  [
    ["/app", "今日行程", "calendar", schedule()],
    ["/app/calendar", "行事曆", "calendar", schedule() || can("meetings.read.own") || can("meetings.read.all")],
    ["/app/changes", "異動通知", "bell", schedule()],
    [
      "/app/invitations",
      schedule() ? "邀請與通知" : "通知收件匣",
      "mail",
      true,
    ],
    ["/app/group-news", "小組消息", "people", true],
    [
      "/app/resources",
      "資源下載",
      "download",
      can("resources.read.own") || can("resources.read.all"),
    ],
    [
      "/app/forms",
      "我的表單",
      "form",
      can("forms.read.own") || can("forms.read.all"),
    ],
    ["/app/profile", "我的帳戶", "person", true],
  ].filter((link) => link[3]),
);
const managementIcons: Record<string, string> = {
  schedule: "calendar",
  classes: "book",
  users: "people",
  "login-records": "history",
  roles: "shield",
  content: "edit",
  products: "box",
  cases: "folder",
  meetings: "people",
  forms: "form",
  reports: "chart",
  settings: "settings",
  prisons: "home",
  groups: "people",
};
const managementIcon = (path: string) =>
  managementIcons[path.split("?")[0]!.split("/").pop() || ""] || "folder";
const activeManagementGroup = ref("");
const activeLink = (path: string) => {
  const [pathname, query] = path.split("?");
  return (
    route.path === pathname &&
    (!query ||
      route.query.section === new URLSearchParams(query).get("section") ||
      (!route.query.section && query === "section=news"))
  );
};
function syncManagementGroup() {
  activeManagementGroup.value =
    managementGroups.value.find((group) =>
      group.links.some((link) => activeLink(link[0])),
    )?.title || "";
}
watch(() => route.fullPath, syncManagementGroup);
watch(
  managementGroups,
  (groups) => {
    // Auth refresh must not close a category the user has just expanded.
    if (!groups.some((group) => group.title === activeManagementGroup.value))
      syncManagementGroup();
  },
  { immediate: true },
);
watch(
  () => route.fullPath,
  () => closeMenu(),
);
watch(
  () => managementLinks.value.length,
  (count) => {
    if (!count && openMenu.value === "management") closeMenu();
  },
);
function closeMenu(restoreFocus = false) {
  const trigger =
    openMenu.value === "management"
      ? managementButton.value
      : serviceButton.value;
  openMenu.value = null;
  if (restoreFocus) trigger?.focus();
}
function toggleMenu(group: "service" | "management") {
  openMenu.value = openMenu.value === group ? null : group;
}
</script>
<template>
  <div class="app-shell">
    <header v-if="ready" class="workspace-mobile-header" @keydown.esc.prevent="closeMenu(true)">
      <NuxtLink class="brand workspace-brand" to="/" aria-label="協會 Logo，回到官網" @click="closeMenu()">
        <img class="workspace-logo" :src="logoUrl" alt="中華復甦更新發展協會標誌" />
        <span><span class="workspace-brand-full">{{ associationName }}</span><span class="workspace-brand-short">復甦更新</span></span>
      </NuxtLink>
      <div class="workspace-menu-buttons" aria-label="工作台選單">
        <button ref="serviceButton" type="button" aria-controls="service-navigation" :aria-expanded="openMenu === 'service'" :class="{ selected: openMenu === 'service' }" @click="toggleMenu('service')"><NavIcon name="person" />我的服務<NavIcon name="chevron" /></button>
        <button v-if="managementLinks.length" ref="managementButton" type="button" aria-controls="management-navigation" :aria-expanded="openMenu === 'management'" :class="{ selected: openMenu === 'management' }" @click="toggleMenu('management')"><NavIcon name="settings" />管理工作台<NavIcon name="chevron" /></button>
      </div>
      <NotificationBell />
    </header>
    <div v-if="ready && openMenu" class="workspace-menu-backdrop" @click="closeMenu()" />
    <aside v-if="ready && openMenu" class="workspace-navigation" @keydown.esc.prevent="closeMenu(true)">
      <section v-if="openMenu === 'service'" class="navigation-group">
        <div class="workspace-menu-heading"><h2>我的服務</h2><button type="button" class="button ghost" @click="closeMenu(true)">關閉</button></div>
        <nav id="service-navigation" aria-label="我的服務" class="navigation-grid">
          <NuxtLink v-for="link in serviceLinks" :key="String(link[0])" :to="String(link[0])" @click="closeMenu()"><NavIcon :name="String(link[2])" /><span>{{ link[1] }}</span></NuxtLink>
          <button type="button" class="logout-link" @click="closeMenu(); logout();"><NavIcon name="logout" /><span>登出</span></button>
        </nav>
      </section>
      <section v-if="openMenu === 'management'" class="navigation-group">
        <div class="workspace-menu-heading"><h2>管理工作台</h2><button type="button" class="button ghost" @click="closeMenu(true)">關閉</button></div>
        <nav id="management-navigation" aria-label="管理工作台" class="management-groups">
          <details v-for="group in managementGroups" :key="group.title" :open="activeManagementGroup === group.title" class="management-category">
            <summary @click.prevent="activeManagementGroup = activeManagementGroup === group.title ? '' : group.title"><NavIcon :name="group.icon" /><span>{{ group.title }}</span><NavIcon name="chevron" /></summary>
            <div class="management-submenu"><NuxtLink v-for="link in group.links" :key="link[0]" :to="link[0]" active-class="" exact-active-class="" :class="{ 'selected-management-link': activeLink(link[0]) }" @click="closeMenu()"><NavIcon :name="managementIcon(link[0])" /><span>{{ link[1] }}</span></NuxtLink></div>
          </details>
        </nav>
      </section>
    </aside>
    <main v-if="ready" class="workspace" :data-workspace-route="route.path">
      <nav v-if="route.path !== '/app/resources'" class="workspace-breadcrumb" aria-label="所在位置"><NuxtLink to="/app">我的服務</NuxtLink><span aria-hidden="true">/</span><span>{{ breadcrumb }}</span></nav>
      <slot />
    </main>
  </div>
</template>
