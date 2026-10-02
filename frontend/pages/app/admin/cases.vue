<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const route = useRoute();
const router = useRouter();
const users = ref<any[]>([]),
  caseRows = ref<any[]>([]),
  caseRowsLoaded = ref(false),
  error = ref("");
const activeTab = computed<"directory" | "records">({
  get: () => route.query.section === "records" ? "records" : "directory",
  set: (tab) => {
    void router.replace({ query: { ...route.query, section: tab === "records" ? "records" : undefined } });
  },
});
const canAssign = () =>
  can("cases.create.all") && can("cases.update.all") && can("users.read.all");
const canCreate = () => can("cases.create.all");
const canUpdate = () => can("cases.update.all") || can("cases.update.assigned");
const { prisons, load: loadPrisons } = usePrisons();
const fields = computed(() => [
  { key: "code", label: "個案代碼" },
  { key: "name", label: "姓名" },
  { key: "status", label: "狀態" },
  {
    key: "prison_id",
    label: "監所",
    type: "select",
    options: prisons.value,
    displayKey: "prison",
    optional: true,
  },
  { key: "contact", label: "聯絡方式", optional: true },
  {
    key: "assigned_user_id",
    label: "負責同工",
    displayKey: "assigned_user_name",
    type: "select",
    options: users.value,
    optional: true,
    readonly: !canAssign(),
  },
]);

onMounted(async () => {
  await loadPrisons();
  if (!canAssign()) return;
  try {
    users.value = (await api<any>("/users")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
});
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">CARE</p>
      <h1>個案紀錄</h1>
      <p class="muted">個案資料依權限顯示；請避免在摘要中放入敏感資訊。</p>
    </div>
  </div>
  <nav class="case-tabs" aria-label="個案紀錄分類">
    <button
      type="button"
      :class="{ selected: activeTab === 'directory' }"
      :aria-pressed="activeTab === 'directory'"
      @click="activeTab = 'directory'"
    >
      個案名冊</button
    ><button
      type="button"
      :class="{ selected: activeTab === 'records' }"
      :aria-pressed="activeTab === 'records'"
      @click="activeTab = 'records'"
    >
      服務紀錄
    </button>
  </nav>
  <section v-show="activeTab === 'directory'">
    <EntityBoard
      title="個案名冊"
      endpoint="/cases"
      :fields="fields"
      :can-create="canCreate()"
      :can-update="canUpdate()"
      @updated="caseRows = $event; caseRowsLoaded = true"
    />
    <p v-if="error" class="notice">
      負責同工選單暫時無法取得，既有指派仍會保留。
    </p>
  </section>
  <section v-show="activeTab === 'records'" class="records-pane">
    <CaseRecordEntry :cases="caseRows" :loaded="caseRowsLoaded" :can-write="canUpdate()" />
  </section>
</template>
<style scoped>
.case-tabs {
  display: flex;
  gap: 8px;
  margin: 18px 0;
  border-bottom: 1px solid var(--line);
}
.case-tabs button {
  min-height: 44px;
  padding: 10px 18px;
  background: transparent;
  border: 0;
  border-bottom: 3px solid transparent;
  color: var(--muted);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}
.case-tabs button.selected {
  color: var(--pine);
  border-color: var(--gold);
}
.records-pane {
  padding-top: 0;
}
@media (max-width: 760px) {
  .case-tabs {
    overflow-x: auto;
  }
  .case-tabs button {
    flex: 1;
    white-space: nowrap;
  }
}
</style>
