<script setup lang="ts">
const props = defineProps<{ name: string }>();
const paths: Record<string, string[]> = {
  home: ["M3 10 12 3l9 7", "M5 9v12h5v-7h4v7h5V9"],
  person: ["M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0", "M4 21v-2a8 8 0 0 1 16 0v2"],
  people: [
    "M13 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0",
    "M3 21v-3a7 7 0 0 1 14 0v3",
    "M17 4a3 3 0 0 1 0 6",
    "M19 14a6 6 0 0 1 3 5v2",
  ],
  calendar: [
    "M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2",
    "M7 3v4m10-4v4M3 10h18M7 14h3m4 0h3m-10 4h3",
  ],
  bell: ["M5 17h14l-2-3V9a5 5 0 0 0-10 0v5l-2 3", "M10 21h4M12 2v2"],
  mail: ["M3 5h18v14H3z", "m3 6 9 7 9-7"],
  download: ["M12 3v12m-5-5 5 5 5-5", "M4 16v5h16v-5"],
  form: ["M5 3h14v18H5z", "M8 7h8M8 11h8M8 15h5M8 18h5"],
  book: [
    "M12 5v16",
    "M12 5C9 3 5 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 1",
  ],
  shield: ["M12 2 3 6v6c0 5 5 8 9 10 4-2 9-5 9-10V6l-9-4", "m8 12 3 3 5-6"],
  edit: ["M14 4 20 10", "m4 14 12-12 6 6-12 12-7 1 1-7", "M3 21h18"],
  box: ["m3 7 9-4 9 4v11l-9 4-9-4V7", "m3 7 9 4 9-4M12 11v11M7 5l10 4"],
  folder: ["M3 6h7l2 3h9v11H3V6"],
  chart: ["M3 3v18h18", "M7 17v-5m5 5V7m5 10v-8"],
  settings: [
    "M9 3h6l1 4 4 2v6l-4 2-1 4H9l-1-4-4-2V9l4-2 1-4",
    "M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0",
  ],
  logout: ["M9 3H3v18h6", "M8 12h13m-4-4 4 4-4 4"],
  chevron: ["m7 10 5 5 5-5"],
};
const iconPaths = computed(() => paths[props.name] || paths.folder);
</script>
<template>
  <svg
    class="nav-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path v-for="(path, index) in iconPaths" :key="index" :d="path" />
  </svg>
</template>
<style scoped>
.nav-icon {
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
}
</style>
