<script setup lang="ts">
definePageMeta({ layout: "app" });
const settings = reactive<any>({
    association_name: "",
    contact_phone: "",
    contact_email: "",
    address: "",
  }),
  line = reactive<any>({ bound: false, subscribed: false }),
  folders = ref<any[]>([]),
  driveName = ref("");
const saved = ref("");
const { error, run } = useApiError();
onMounted(async () => {
  try {
    Object.assign(settings, await api("/settings"));
    Object.assign(line, await api("/integrations/line"));
    folders.value = (await api<any>("/integrations/drive")).data || [];
  } catch (e: any) {
    error.value = e.message;
  }
});
async function save() {
  await run(() => api("/settings", { method: "PUT", body: settings }));
  saved.value = "設定已儲存";
}
async function saveLine() {
  await run(() => api("/integrations/line", { method: "PUT", body: line }));
  saved.value = "LINE 模擬設定已更新";
}
async function drive(action: string) {
  await run(() =>
    api("/integrations/drive/simulate", {
      method: "POST",
      body: { action, name: driveName.value },
    }),
  );
  saved.value = `已模擬雲端 ${action}`;
}
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">SETTINGS</p>
      <h1>整合與設定</h1>
    </div>
  </div>
  <div class="grid" style="grid-template-columns: 1fr 1fr">
    <form class="card form" @submit.prevent="save">
      <h3>協會資料</h3>
      <label class="field"
        >名稱<input v-model="settings.association_name" /></label
      ><label class="field"
        >電話<input v-model="settings.contact_phone" /></label
      ><label class="field"
        >信箱<input v-model="settings.contact_email" /></label
      ><label class="field">地址<input v-model="settings.address" /></label
      ><button class="button">儲存設定</button>
    </form>
    <div class="grid">
      <form class="card form" @submit.prevent="saveLine">
        <h3>LINE 通知（模擬）</h3>
        <label><input v-model="line.bound" type="checkbox" /> 已綁定帳號</label
        ><label
          ><input v-model="line.subscribed" type="checkbox" /> 訂閱通知</label
        ><button class="button">更新模擬狀態</button>
      </form>
      <form class="card form" @submit.prevent="drive('upload')">
        <h3>雲端硬碟（模擬）</h3>
        <p class="muted">
          {{ folders.map((x) => x.name || x).join("、") || "尚無資料夾" }}
        </p>
        <input v-model="driveName" placeholder="檔案名稱" />
        <div class="actions">
          <button class="button">模擬上傳</button
          ><button
            type="button"
            class="button ghost"
            @click="drive('download')"
          >
            模擬下載
          </button>
        </div>
      </form>
    </div>
  </div>
  <p v-if="saved" class="notice">{{ saved }}</p>
  <p v-if="error" class="error">{{ error }}</p>
</template>
