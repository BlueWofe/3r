<script setup lang="ts">
const { user, logout, refresh, can } = useAuth();
const { schedule, managementLinks, managementGroups } =
  useWorkspaceNavigation();
const ready = ref(false);
const openMenu = ref<"service" | "management" | null>(null);
const serviceButton = ref<HTMLButtonElement | null>(null);
const managementButton = ref<HTMLButtonElement | null>(null);
const route = useRoute();
const serviceLinks = computed(() =>
  [
    ["/app", "今日行程", "calendar", schedule()],
    ["/app/calendar", "行事曆", "calendar", schedule()],
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
    ["/app/profile", "個人資料與奉獻", "person", true],
  ].filter((link) => link[3]),
);
const managementIcons: Record<string, string> = {
  schedule: "calendar",
  classes: "book",
  users: "people",
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
onMounted(async () => {
  await refresh();
  if (!user.value) await navigateTo("/login");
  else ready.value = true;
});
</script>
<template>
  <div class="app-shell">
    <header
      v-if="ready"
      class="workspace-mobile-header"
      @keydown.esc.prevent="closeMenu(true)"
    >
      <NuxtLink
        class="brand workspace-brand"
        to="/"
        aria-label="協會 Logo，回到官網"
        @click="closeMenu()"
        ><span class="backend-logo-frame"
          ><img
            src="/images/association-backend-logo.png"
            alt="中華復甦更新發展協會後台標誌" /></span
        ><span>復甦更新</span></NuxtLink
      >
      <div class="workspace-menu-buttons" aria-label="工作台選單">
        <button
          ref="serviceButton"
          type="button"
          aria-controls="service-navigation"
          :aria-expanded="openMenu === 'service'"
          :class="{ selected: openMenu === 'service' }"
          @click="toggleMenu('service')"
        >
          <NavIcon name="person" />我的服務<NavIcon name="chevron" />
        </button>
        <button
          v-if="managementLinks.length"
          ref="managementButton"
          type="button"
          aria-controls="management-navigation"
          :aria-expanded="openMenu === 'management'"
          :class="{ selected: openMenu === 'management' }"
          @click="toggleMenu('management')"
        >
          <NavIcon name="settings" />管理工作台<NavIcon name="chevron" />
        </button>
      </div>
    </header>
    <aside
      v-if="ready"
      :class="['side', { 'menu-open': openMenu }]"
      @keydown.esc.prevent="closeMenu(true)"
    >
      <NuxtLink
        class="brand desktop-brand"
        to="/"
        aria-label="協會 Logo，回到官網"
        ><span class="backend-logo-frame"
          ><img
            src="/images/association-backend-logo.png"
            alt="中華復甦更新發展協會後台標誌" /></span
        >復甦更新</NuxtLink
      >
      <section
        :class="[
          'navigation-group',
          { 'mobile-active': openMenu === 'service' },
        ]"
      >
        <h2 class="group"><NavIcon name="person" />我的服務</h2>
        <nav
          id="service-navigation"
          aria-label="我的服務"
          class="navigation-grid"
        >
          <NuxtLink
            v-for="link in serviceLinks"
            :key="String(link[0])"
            :to="String(link[0])"
            @click="closeMenu()"
            ><NavIcon :name="String(link[2])" /><span>{{
              link[1]
            }}</span></NuxtLink
          >
          <button
            type="button"
            class="logout-link"
            @click="
              closeMenu();
              logout();
            "
          >
            <NavIcon name="logout" /><span>登出</span>
          </button>
        </nav>
      </section>
      <section
        v-if="managementLinks.length"
        :class="[
          'navigation-group',
          { 'mobile-active': openMenu === 'management' },
        ]"
      >
        <h2 class="group"><NavIcon name="settings" />管理工作台</h2>
        <nav
          id="management-navigation"
          aria-label="管理工作台"
          class="navigation-grid management-groups"
        >
          <details
            v-for="group in managementGroups"
            :key="group.title"
            :open="activeManagementGroup === group.title"
            class="management-category"
          >
            <summary
              @click.prevent="
                activeManagementGroup =
                  activeManagementGroup === group.title ? '' : group.title
              "
            >
              <NavIcon :name="group.icon" /><span>{{ group.title }}</span
              ><NavIcon name="chevron" />
            </summary>
            <div class="management-submenu">
              <NuxtLink
                v-for="link in group.links"
                :key="link[0]"
                :to="link[0]"
                active-class=""
                exact-active-class=""
                :class="{ 'selected-management-link': activeLink(link[0]) }"
                @click="closeMenu()"
                ><NavIcon :name="managementIcon(link[0])" /><span>{{
                  link[1]
                }}</span></NuxtLink
              >
            </div>
          </details>
        </nav>
      </section>
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
.app-shell {
  display: block !important;
}
.workspace {
  min-width: 0;
  padding: 28px clamp(16px, 4vw, 56px);
}
.side {
  display: none;
  position: fixed;
  top: 101px;
  left: 50%;
  z-index: 35;
  width: min(720px, calc(100vw - 32px));
  max-height: min(320px, calc(100dvh - 100px));
  height: auto;
  transform: translateX(-50%);
  overflow-y: auto;
  padding: 12px;
  border-radius: 16px;
  background: var(--pine);
  color: #fff;
  border: 1px solid rgba(198, 157, 77, 0.4);
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.28);
}
.workspace-mobile-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px clamp(16px, 4vw, 56px);
  background: linear-gradient(180deg, #164538, #0e3026);
  color: #fff;
  position: sticky;
  top: 0;
  z-index: 40;
  border-bottom: 3px solid var(--gold);
  box-shadow: 0 10px 24px rgba(14, 48, 38, 0.16);
}
.workspace-brand {
  margin-right: auto;
  color: #fff;
}
.backend-logo-frame {
  position: relative;
  width: 78px;
  height: 78px;
  overflow: hidden;
  display: block;
  flex: 0 0 auto;
  border-radius: 10px;
  background: #f7f0df;
}
.backend-logo-frame img {
  position: absolute;
  width: 120px;
  height: 150px;
  max-width: none;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}
.side.menu-open {
  display: block;
}
.side .desktop-brand,
.side .desktop-return {
  display: none;
}
.navigation-group {
  display: none;
  margin: 0;
}
.navigation-group.mobile-active {
  display: block;
}
.side .group {
  display: none;
}
.workspace-menu-buttons {
  display: flex;
  gap: 8px;
  width: min(480px, 100%);
}
.workspace-menu-buttons button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  flex: 1;
  min-height: 44px;
  border: 1px solid #ffffff66;
  background: rgba(255, 255, 255, 0.06);
  color: inherit;
  border-radius: 999px;
  font: inherit;
  cursor: pointer;
  padding: 6px 10px;
}
.workspace-menu-buttons button.selected {
  background: #ffffff20;
  border-color: var(--gold);
}
.navigation-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  padding: 3px;
}
.side .navigation-grid a,
.logout-link {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 48px;
  padding: 9px;
  font-size: 13px;
  background: #ffffff0c;
  border: 1px solid #ffffff20;
  border-radius: 7px;
  white-space: normal;
  color: inherit;
}
.navigation-grid.management-groups {
  display: block;
}
.management-submenu {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  padding: 8px;
  border: 0;
  margin: 0;
}
.side a,
.logout-link,
.group,
.public-return {
  display: flex;
  align-items: center;
  gap: 9px;
}
.public-return {
  font-weight: 700;
}
.side .desktop-return {
  margin-top: 12px;
  border: 1px solid #ffffff55;
}
.side .group {
  font-size: 13px;
  font-weight: 600;
}
.navigation-group + .navigation-group {
  margin: 0;
  padding: 0;
  border: 0;
}
.logout-link {
  color: inherit;
  border: 0;
  background: transparent;
  padding: 10px 12px;
  width: 100%;
  font: inherit;
  font-size: 14px;
  cursor: pointer;
  border-radius: 5px;
  text-align: left;
}
.logout-link:hover {
  background: #ffffff16;
}
.management-category summary {
  display: flex;
  align-items: center;
  gap: 9px;
  min-height: 46px;
  padding: 10px 12px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  list-style: none;
  border-radius: 7px;
}
.management-category summary::-webkit-details-marker {
  display: none;
}
.management-category summary span {
  flex: 1;
}
.management-category[open] > summary {
  background: #ffffff16;
}
.management-category[open] > summary :last-child {
  transform: rotate(180deg);
}
.management-submenu {
  margin: 5px 0 12px 10px;
  border-left: 1px solid #ffffff30;
  padding-left: 5px;
}
.side .selected-management-link {
  background: #ffffff24;
  border-color: var(--gold);
}
summary:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 2px;
}
a:focus-visible,
button:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
@media (max-width: 760px) {
  .workspace {
    padding: 20px 16px;
  }
  .workspace-brand {
    display: flex;
    min-width: 52px;
    margin-right: 0;
  }
  .workspace-brand > span:last-child {
    display: none;
  }
  .workspace-brand .backend-logo-frame {
    width: 52px;
    height: 52px;
  }
  .workspace-brand .backend-logo-frame img {
    width: 80px;
    height: 100px;
  }
  .workspace-mobile-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: linear-gradient(180deg, #164538, #0e3026);
    color: #fff;
    position: sticky;
    top: 0;
    z-index: 40;
    border-bottom: 3px solid var(--gold);
  }
  .public-return {
    min-height: 32px;
    width: fit-content;
    font-size: 14px;
  }
  .workspace-menu-buttons {
    display: flex;
    gap: 8px;
  }
  .workspace-menu-buttons button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex: 1;
    min-height: 44px;
    border: 1px solid #ffffff66;
    background: rgba(255, 255, 255, 0.06);
    color: inherit;
    border-radius: 999px;
    font: inherit;
    font-size: clamp(11px, 3.4vw, 14px);
    cursor: pointer;
    padding: 6px 8px;
  }
  .workspace-menu-buttons button.selected {
    background: #ffffff20;
    border-color: var(--gold);
  }
  .side {
    display: none;
    position: sticky;
    left: auto;
    transform: none;
    width: auto;
    max-height: none;
    border-radius: 0;
    box-shadow: none;
    top: 75px;
    z-index: 35;
    height: auto;
    padding: 10px 16px 14px;
    overflow: visible;
    white-space: normal;
  }
  @media (max-width: 350px) {
    .workspace-menu-buttons button {
      padding: 5px;
      gap: 3px;
    }
    .workspace-menu-buttons button .nav-icon:last-child {
      display: none;
    }
  }
  .side.menu-open {
    display: block;
  }
  .side .desktop-brand,
  .side .desktop-return {
    display: none;
  }
  .navigation-group {
    display: none;
    margin: 0;
  }
  .navigation-group.mobile-active {
    display: block;
  }
  .navigation-group + .navigation-group {
    margin: 0;
    padding: 0;
    border: 0;
  }
  .side .group {
    display: none;
  }
  .navigation-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    max-height: min(320px, calc(100dvh - 140px));
    overflow-y: auto;
    padding: 3px;
    overscroll-behavior: contain;
  }
  .side .navigation-grid a,
  .logout-link {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 54px;
    padding: 9px;
    font-size: 13px;
    background: #ffffff0c;
    border: 1px solid #ffffff20;
    border-radius: 7px;
    white-space: normal;
  }
  .navigation-grid span {
    min-width: 0;
    overflow-wrap: anywhere;
  }
  .navigation-grid.management-groups {
    display: block;
  }
  .management-category {
    margin-bottom: 6px;
    border: 1px solid #ffffff20;
    border-radius: 7px;
  }
  .management-submenu {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    padding: 8px;
    border: 0;
    margin: 0;
  }
  .side .navigation-grid .router-link-exact-active {
    background: #ffffff24;
    border-color: var(--gold);
  }
}
</style>
