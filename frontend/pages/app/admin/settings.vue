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
  driveName = ref(""),
  logo = ref<File | null>(null),
  logoPreview = ref(""),
  logoPending = ref(false);
const saved = ref("");
function clearLogoPreview() {
  if (logoPreview.value.startsWith("blob:")) URL.revokeObjectURL(logoPreview.value);
  logoPreview.value = "";
}
onBeforeUnmount(clearLogoPreview);
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
  Object.assign(settings, await api("/settings"));
  saved.value = "已更新";
}
function selectLogo(event: Event) {
  clearLogoPreview();
  logo.value = null;
  saved.value = "";
  const file = (event.target as HTMLInputElement).files?.[0] || null;
  if (!file) return;
  if (
    !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
    file.size > 5 * 1024 * 1024
  ) {
    error.value = "Logo 僅接受 JPEG、PNG 或 WebP，大小不得超過 5MB。";
    return;
  }
  logo.value = file;
  logoPreview.value = URL.createObjectURL(file);
  error.value = "";
}
async function uploadLogo() {
  if (!logo.value || logoPending.value) return;
  logoPending.value = true;
  try {
    const data = new FormData();
    data.append("file", logo.value);
    Object.assign(
      settings,
      await run(() => api("/settings/logo", { method: "POST", body: data })),
    );
    clearLogoPreview();
    logo.value = null;
    saved.value = "Logo 已更新";
  } finally {
    logoPending.value = false;
  }
}
async function saveLine() {
  await run(() => api("/integrations/line", { method: "PUT", body: line }));
  Object.assign(line, await api("/integrations/line"));
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
  folders.value = (await api<any>("/integrations/drive")).data || [];
}
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">SETTINGS</p>
      <h1>整合與設定</h1>
    </div>
  </div>
  <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
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
    <form class="card form" @submit.prevent="uploadLogo">
      <h3>協會 Logo</h3>
      <img
        v-if="logoPreview || settings.logo_url"
        class="settings-logo-preview"
        :src="logoPreview || settings.logo_url"
        alt="協會 Logo 預覽"
      />
      <p v-else class="muted">尚未上傳 Logo，將顯示協會識別章。</p>
      <label class="field"
        >選擇 Logo（JPEG、PNG、WebP，最多 5MB）<input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          :disabled="logoPending"
          @change="selectLogo" /></label
      ><button class="button" :disabled="!logo || logoPending">
        {{ logoPending ? "上傳中…" : "上傳並套用 Logo" }}
      </button>
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
