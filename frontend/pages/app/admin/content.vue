<script setup lang="ts">
definePageMeta({ layout: "app" });
type Content = Record<string, any>;
const rows = ref<Content[]>([]),
  open = ref(false),
  editing = ref<Content | null>(null),
  pending = ref(false),
  imageUploading = ref(false),
  error = ref(""),
  image = ref<File | null>(null);
const form = reactive<Content>({});
const escapeHtml = (value: string) =>
  value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
const plainToHtml = (value: string) =>
  value ? `<p>${escapeHtml(value).replaceAll("\n", "<br>")}</p>` : "";
const formatLocalTaipei = (value?: string | null) => {
  if (!value) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Taipei",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(value));
  const find = (type: string) =>
    parts.find((part) => part.type === type)?.value || "";
  return `${find("year")}-${find("month")}-${find("day")}T${find("hour")}:${find("minute")}`;
};
const toTaipeiIso = (value: string) => (value ? `${value}:00+08:00` : null);
const statusLabel = (record: Content) => {
  if (record.status !== "published") return "草稿";
  return record.published_at && new Date(record.published_at) > new Date()
    ? "排程中"
    : "已發布";
};
async function load() {
  try {
    rows.value = (
      (await api<{ data: Content[] }>("/contents")).data || []
    ).filter((row) => row.kind !== "product");
    error.value = "";
  } catch (caught: any) {
    error.value = caught.message;
  }
}
function reset(record?: Content) {
  editing.value = record || null;
  Object.keys(form).forEach((key) => delete form[key]);
  Object.assign(form, {
    kind: record?.kind || "news",
    title: record?.title || "",
    slug: record?.slug || "",
    summary: record?.summary || "",
    category: record?.category || "",
    status: record?.status || "draft",
    sort_order: record?.sort_order ?? 0,
    image_id: record?.image_id || null,
    author_name: record?.author_name || "",
    published_at: formatLocalTaipei(record?.published_at),
    body_html:
      record?.body_html ||
      (record?.body_format === "html"
        ? record.body || ""
        : plainToHtml(record?.body || "")),
    body_format: "html",
  });
  image.value = null;
  error.value = "";
  open.value = true;
}
async function save() {
  if (pending.value || imageUploading.value) return;
  pending.value = true;
  error.value = "";
  try {
    const body: Content = {
      ...form,
      body: form.body_html,
      body_format: "html",
      published_at: toTaipeiIso(form.published_at),
    };
    if (image.value) {
      const payload = new FormData();
      payload.append("file", image.value);
      payload.append("visibility", "public");
      payload.append("title", image.value.name);
      body.image_id = (
        await api<any>("/files", { method: "POST", body: payload })
      ).id;
    }
    if (editing.value?.version) body.version = editing.value.version;
    await api(editing.value ? `/contents/${editing.value.id}` : "/contents", {
      method: editing.value ? "PUT" : "POST",
      body,
    });
    open.value = false;
    await load();
  } catch (caught: any) {
    error.value = caught.message;
  } finally {
    pending.value = false;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">CMS</p>
      <h1>內容管理</h1>
      <p class="muted">
        建立並發布協會頁面與消息；公開文章可使用公開圖片與安全本文。
      </p>
    </div>
    <button class="button" @click="reset()">新增</button>
  </div>
  <p v-if="error && !open" class="notice">{{ error }}</p>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>類型</th>
          <th>標題</th>
          <th>分類</th>
          <th>作者</th>
          <th>發布時間</th>
          <th>狀態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td>{{ row.kind === "news" ? "消息" : "頁面" }}</td>
          <td>{{ row.title }}</td>
          <td>{{ row.category || "—" }}</td>
          <td>{{ row.author_name || "—" }}</td>
          <td>{{ formatLocalTaipei(row.published_at) || "尚未設定" }}</td>
          <td>{{ statusLabel(row) }}</td>
          <td>
            <button class="button ghost" @click="reset(row)">編輯</button>
          </td>
        </tr>
        <tr v-if="!rows.length">
          <td colspan="7" class="empty">尚無內容</td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="open" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ editing ? "編輯" : "新增" }}內容</h2>
        <button
          type="button"
          class="button ghost"
          :disabled="pending"
          @click="open = false"
        >
          關閉
        </button>
      </div>
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field"
          >類型<select v-model="form.kind">
            <option value="news">消息</option>
            <option value="page">頁面</option>
          </select></label
        ><label class="field">標題<input v-model="form.title" required /></label
        ><label class="field"
          >網址代稱<input v-model="form.slug" required /></label
        ><label class="field"
          >分類<input
            v-model="form.category"
            list="category-options"
            placeholder="最新消息或見證分享" /><datalist id="category-options">
            <option value="最新消息" />
            <option value="見證分享" /></datalist></label
        ><label class="field"
          >作者<input
            v-model="form.author_name"
            placeholder="留白時由系統帶入目前作者" /></label
        ><label class="field"
          >發布時間<input
            v-model="form.published_at"
            type="datetime-local"
          /><small class="muted"
            >首次發布留白時由系統使用目前時間。</small
          ></label
        ><label class="field"
          >狀態<select v-model="form.status">
            <option value="draft">草稿</option>
            <option value="published">已發布</option>
          </select></label
        ><label class="field"
          >排序<input v-model.number="form.sort_order" type="number" /></label
        ><label class="field"
          >公開圖片<input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="
              image = ($event.target as HTMLInputElement).files?.[0] || null
            "
        /></label>
      </div>
      <label class="field">摘要<textarea v-model="form.summary" /></label
      ><label class="field">本文</label
      ><ClientOnly
        ><RichTextEditor
          v-model="form.body_html"
          @uploading="imageUploading = $event"
      /></ClientOnly>
      <details class="card">
        <summary>文章預覽</summary>
        <ClientOnly><RichTextPreview :html="form.body_html" /></ClientOnly>
      </details>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button" :disabled="pending || imageUploading">
        {{ pending || imageUploading ? "儲存中…" : "儲存" }}
      </button>
    </form>
  </div>
</template>
