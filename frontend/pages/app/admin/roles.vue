<script setup lang="ts">
definePageMeta({ layout: "app" });
const roles = ref<any[]>([]),
  permissions = ref<string[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  form = reactive<any>({ name: "", slug: "", active: true, permissions: [] });
const { error, run } = useApiError();
const permissionMenus = [
  {
    module: "prisons",
    title: "監所管理",
    labels: { "manage.all": "新增、編輯及停用監所" },
  },
  {
    module: "schedule",
    title: "行事曆・排程管理・班別管理",
    note: "課程與班別共用排課權限。",
    labels: {
      "read.own": "查看自己的課程",
      "read.all": "查看所有課程與班別",
      "update.own": "異動自己的課程、請假及代課",
      "update.all": "管理所有排課與班別異動",
      "create.all": "新增課程與班別",
    },
  },
  {
    module: "attendance",
    title: "出勤打卡",
    labels: {
      "create.own": "為自己的課程打卡",
      "update.all": "補登及更正所有老師的出勤",
    },
  },
  {
    module: "content",
    title: "內容管理・產品管理",
    note: "文章、協會頁面與產品共用內容權限。",
    labels: {
      "read.all": "查看所有內容與產品",
      "create.all": "新增內容與產品",
      "update.all": "編輯內容與產品",
      "delete.all": "刪除內容與產品",
      "publish.all": "發布內容與產品",
    },
  },
  {
    module: "users",
    title: "人員與角色",
    labels: {
      "read.all": "查看人員資料",
      "create.all": "新增人員",
      "update.all": "編輯及停用人員",
    },
  },
  {
    module: "roles",
    title: "角色與權限",
    note: "授予此權限的人員可管理角色與授權。",
    labels: { "manage.all": "管理角色、權限及人員角色指派" },
  },
  {
    module: "cases",
    title: "個案紀錄",
    labels: {
      "read.assigned": "查看被指派的個案",
      "read.all": "查看所有個案",
      "create.all": "新增個案",
      "update.assigned": "編輯被指派的個案",
      "update.all": "編輯所有個案",
      "export.all": "匯出個案資料",
    },
  },
  {
    module: "resources",
    title: "資源下載與管理",
    labels: {
      "read.own": "查看及下載獲授權的資源",
      "read.all": "查看及下載所有資源",
      "create.own": "上傳至獲授權的資源區",
      "create.all": "新增所有範圍的資源",
      "update.all": "編輯所有資源",
      "delete.all": "刪除所有資源",
    },
  },
  {
    module: "meetings",
    title: "會議管理",
    labels: {
      "read.own": "查看獲授權的會議與紀錄",
      "read.all": "查看所有會議與紀錄",
      "create.all": "新增會議與紀錄",
      "update.all": "編輯會議與紀錄",
    },
  },
  {
    module: "forms",
    title: "我的表單・表單中心",
    labels: {
      "read.own": "查看及填寫獲授權的表單",
      "read.all": "查看所有表單",
      "create.all": "新增表單",
      "update.all": "編輯及發布表單",
      "export.all": "匯出表單回覆",
    },
  },
  {
    module: "donations",
    title: "個人資料與奉獻",
    labels: {
      "read.own": "查看自己的奉獻紀錄",
      "read.all": "查看所有奉獻紀錄",
    },
  },
  {
    module: "reports",
    title: "服務報表",
    labels: {
      "read.own": "查看自己的服務報表",
      "read.all": "查看所有服務報表",
    },
  },
  {
    module: "settings",
    title: "協會設定",
    labels: { "manage.all": "管理協會聯絡資訊與系統設定" },
  },
] as const;
const permissionGroups = computed(() =>
  permissionMenus
    .map((menu) => ({
      ...menu,
      note: "note" in menu ? menu.note : "",
      items: permissions.value
        .filter((key) => key.startsWith(`${menu.module}.`))
        .map((key) => ({
          key,
          label:
            (menu.labels as Record<string, string>)[
              key.slice(menu.module.length + 1)
            ] || "其他授權項目",
        })),
    }))
    .filter((group) => group.items.length),
);
const selectedCount = (keys: string[]) =>
  keys.filter((key) => form.permissions.includes(key)).length;
function selectPermissions(keys: string[], selected: boolean) {
  form.permissions = selected
    ? [...new Set<string>([...form.permissions, ...keys])]
    : form.permissions.filter((key: string) => !keys.includes(key));
}
async function load() {
  [roles.value, permissions.value] = await Promise.all([
    api<any>("/roles").then((x) => x.data || []),
    api<any>("/permissions").then((x) => x.data || []),
  ]);
}
function edit(r?: any) {
  editing.value = r || null;
  Object.assign(form, {
    name: r?.name || "",
    slug: r?.slug || "",
    active: r?.active ?? true,
    permissions: [...(r?.permissions || [])],
  });
  open.value = true;
}
async function save() {
  await run(() =>
    api(editing.value ? `/roles/${editing.value.id}` : "/roles", {
      method: editing.value ? "PUT" : "POST",
      body: { ...form },
    }),
  );
  open.value = false;
  await load();
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">ROLE POLICY</p>
      <h1>角色與權限</h1>
    </div>
    <button class="button" @click="edit()">新增角色</button>
  </div>
  <div class="grid cards">
    <article v-for="r in roles" :key="r.id" class="card">
      <span class="status">{{ r.active ? "啟用" : "停用" }}</span>
      <h3>{{ r.name }}</h3>
      <p class="muted">
        {{ r.slug }}<br />{{ r.permissions?.length || 0 }} 項權限
      </p>
      <button class="button ghost" @click="edit(r)">設定權限</button>
    </article>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>角色設定</h2>
        <button type="button" class="button ghost" @click="open = false">
          關閉
        </button>
      </div>
      <label class="field">名稱<input v-model="form.name" required /></label
      ><label class="field">代稱<input v-model="form.slug" required /></label
      ><label><input v-model="form.active" type="checkbox" /> 啟用角色</label>
      <section class="permission-settings" aria-label="權限設定">
        <div class="permission-heading">
          <div>
            <h3>權限設定</h3>
            <p class="muted" aria-live="polite">
              已選 {{ selectedCount(permissions) }}／{{ permissions.length }} 項
            </p>
          </div>
          <div class="permission-actions">
            <button
              type="button"
              class="button ghost"
              @click="selectPermissions(permissions, true)"
            >
              全選
            </button>
            <button
              type="button"
              class="button ghost"
              @click="selectPermissions(permissions, false)"
            >
              全不選
            </button>
          </div>
        </div>
        <p v-if="editing?.slug === 'system-admin'" class="notice">
          系統管理員固定擁有完整管理權限，不受下列勾選限制。
        </p>
        <fieldset
          v-for="group in permissionGroups"
          :key="group.module"
          class="permission-group"
        >
          <legend>{{ group.title }}</legend>
          <p v-if="group.note" class="muted">{{ group.note }}</p>
          <div class="permission-heading">
            <small class="muted"
              >已選 {{ selectedCount(group.items.map((item) => item.key)) }}／{{
                group.items.length
              }}
              項</small
            >
            <div class="permission-actions">
              <button
                type="button"
                class="button ghost"
                :aria-label="`${group.title}：全選`"
                @click="
                  selectPermissions(
                    group.items.map((item) => item.key),
                    true,
                  )
                "
              >
                全選
              </button>
              <button
                type="button"
                class="button ghost"
                :aria-label="`${group.title}：全不選`"
                @click="
                  selectPermissions(
                    group.items.map((item) => item.key),
                    false,
                  )
                "
              >
                全不選
              </button>
            </div>
          </div>
          <div class="permission-options">
            <label v-for="item in group.items" :key="item.key"
              ><input
                v-model="form.permissions"
                type="checkbox"
                :value="item.key"
              /><span>{{ item.label }}</span></label
            >
          </div>
        </fieldset>
      </section>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存角色</button>
    </form>
  </div>
</template>
<style scoped>
.permission-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.permission-heading h3,
.permission-heading p {
  margin: 0;
}
.permission-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.permission-group {
  min-width: 0;
  margin: 18px 0 0;
  padding: 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
}
.permission-group legend {
  padding: 0 6px;
  font-weight: 700;
}
.permission-group > p {
  margin-top: 0;
  font-size: 14px;
}
.permission-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 16px;
  margin-top: 12px;
}
.permission-options label {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  cursor: pointer;
  overflow-wrap: anywhere;
}
.permission-options input {
  flex: 0 0 auto;
  width: 18px;
  height: 18px;
  accent-color: var(--pine);
}
@media (max-width: 760px) {
  .permission-options {
    grid-template-columns: 1fr;
  }
  .permission-group {
    padding: 12px;
  }
}
</style>
