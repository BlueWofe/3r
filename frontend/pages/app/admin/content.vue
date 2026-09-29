<script setup lang="ts">
definePageMeta({ layout: "app" });
type Content = Record<string, any>;
const allRows = ref<Content[]>([]),
  open = ref(false),
  editing = ref<Content | null>(null),
  pending = ref(false),
  imageUploading = ref(false),
  error = ref(""),
  image = ref<File | null>(null),
  groups = ref<any[]>([]),
  broadcasts = ref<any[]>([]),
  broadcastPending = ref(false),
  success = ref("");
const form = reactive<Content>({});
const route = useRoute();
const router = useRouter();
const sections = [
  {
    id: "news",
    title: "最新消息",
    description: "協會公告與事工近況",
    icon: "bell",
  },
  {
    id: "sharing",
    title: "事工分享",
    description: "事工中的同行與回應",
    icon: "people",
  },
  {
    id: "testimony",
    title: "見證分享",
    description: "陪伴與生命更新的故事",
    icon: "people",
  },
  {
    id: "pages",
    title: "協會頁面",
    description: "協會介紹、沿革與組織",
    icon: "book",
  },
];
const sectionOf = (record: Content) =>
  record.kind === "page"
    ? "pages"
    : record.article_type === "sharing"
      ? "sharing"
      : record.article_type === "testimony" || record.category === "見證分享"
        ? "testimony"
        : "news";
const section = computed(() =>
  sections.some((item) => item.id === route.query.section)
    ? String(route.query.section)
    : "news",
);
const rows = computed(() =>
  allRows.value.filter((record) => sectionOf(record) === section.value),
);
const sectionTitle = computed(
  () => sections.find((item) => item.id === section.value)?.title || "最新消息",
);
const switchSection = (id: string) =>
  router.replace({ query: { ...route.query, section: id } });
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
    allRows.value = (
      (await api<{ data: Content[] }>("/contents")).data || []
    ).filter((row) => row.kind !== "product");
    try {
      groups.value = (await api<any>("/groups/options")).data || [];
    } catch {
      groups.value = [];
    }
    error.value = "";
  } catch (caught: any) {
    error.value = caught.message;
  }
}
function reset(record?: Content) {
  editing.value = record || null;
  Object.keys(form).forEach((key) => delete form[key]);
  Object.assign(form, {
    kind: record?.kind || (section.value === "pages" ? "page" : "news"),
    title: record?.title || "",
    slug: record?.slug || "",
    summary: record?.summary || "",
    category:
      record?.category ||
      (section.value === "testimony"
        ? "見證分享"
        : section.value === "news"
          ? "最新消息"
          : section.value === "sharing"
            ? "監獄事工"
            : ""),
    article_type:
      record?.article_type ||
      (record?.category === "見證分享"
        ? "testimony"
        : section.value === "sharing"
          ? "sharing"
          : "news"),
    visibility: record?.visibility || "public",
    group_ids: [...(record?.group_ids || [])],
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
    metadata: JSON.parse(JSON.stringify(record?.metadata || {})),
  });
  image.value = null;
  error.value = "";
  success.value = "";
  broadcasts.value = [];
  open.value = true;
  if (record?.id && record.visibility === "groups") loadBroadcasts(record.id);
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
    success.value = "已更新";
    await load();
    await switchSection(sectionOf(body));
  } catch (caught: any) {
    error.value = caught.message;
  } finally {
    pending.value = false;
  }
}
async function loadBroadcasts(id: number) {
  try {
    broadcasts.value =
      (await api<any>(`/contents/${id}/broadcasts`)).data || [];
  } catch {
    broadcasts.value = [];
  }
}
async function broadcast() {
  if (!editing.value || broadcastPending.value) return;
  broadcastPending.value = true;
  error.value = "";
  try {
    const result = await api<any>(`/contents/${editing.value.id}/broadcast`, {
      method: "POST",
      body: { version: editing.value.version },
    });
    success.value = result.duplicate
      ? "此版本已發送通知"
      : `已發送給 ${result.recipient_count} 位小組成員`;
    await loadBroadcasts(editing.value.id);
  } catch (caught: any) {
    error.value = caught.message || "群發失敗";
  } finally {
    broadcastPending.value = false;
  }
}
watch(
  () => form.visibility,
  (visibility) => {
    if (visibility === "groups") {
      form.image_id = null;
      image.value = null;
    }
  },
);
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">CMS</p>
      <h1>內容管理</h1>
      <p class="muted">
        建立協會頁面、最新消息與見證分享，自由編排文字與圖片。
      </p>
    </div>
    <button class="button" @click="reset()">新增</button>
  </div>
  <nav class="content-sections" aria-label="內容分類">
    <button
      v-for="item in sections"
      :key="item.id"
      type="button"
      :class="['content-section', { selected: section === item.id }]"
      :aria-pressed="section === item.id"
      @click="switchSection(item.id)"
    >
      <NavIcon :name="item.icon" /><span
        ><strong>{{ item.title }}</strong
        ><small>{{ item.description }}</small></span
      ><b>{{
        allRows.filter((record) => sectionOf(record) === item.id).length
      }}</b>
    </button>
  </nav>
  <h2 class="content-list-title">{{ sectionTitle }}</h2>
  <p v-if="success && !open" class="notice">{{ success }}</p>
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
          <td data-label="類型">{{ row.kind === "news" ? "消息" : "頁面" }}</td>
          <td data-label="標題">{{ row.title }}</td>
          <td data-label="分類">{{ row.category || "—" }}</td>
          <td data-label="作者">{{ row.author_name || "—" }}</td>
          <td data-label="發布時間">
            {{ formatLocalTaipei(row.published_at) || "尚未設定" }}
          </td>
          <td data-label="狀態">{{ statusLabel(row) }}</td>
          <td data-label="操作">
            <button class="button ghost" @click="reset(row)">編輯</button>
          </td>
        </tr>
        <tr v-if="!rows.length">
          <td colspan="7" class="empty">尚無{{ sectionTitle }}</td>
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
        ><label v-if="form.kind === 'news'" class="field"
          >文章類型<select v-model="form.article_type">
            <option value="news">最新消息</option>
            <option value="sharing">事工分享</option>
            <option value="testimony">生命見證</option>
          </select></label
        ><label class="field">標題<input v-model="form.title" required /></label
        ><label class="field"
          >網址代稱<input v-model="form.slug" required /></label
        ><label class="field"
          >分類<input
            v-model="form.category"
            list="category-options"
            placeholder="最新消息或見證分享" /><datalist id="category-options">
            <option value="監獄事工" />
            <option value="更生輔導" />
            <option value="志工招募" />
            <option value="愛心義賣" />
            <option value="代禱消息" />
            <option value="協會公告" />
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
        ><label v-if="form.kind === 'news'" class="field"
          >公開範圍<select v-model="form.visibility">
            <option value="public">公開文章</option>
            <option value="groups">限定小組</option>
          </select></label
        ><label
          v-if="form.kind === 'news' && form.visibility === 'groups'"
          class="field"
          >指定小組（可複選）<select v-model="form.group_ids" multiple required>
            <option v-for="group in groups" :key="group.id" :value="group.id">
              {{ group.name }}
            </option></select
          ><small class="muted">小組消息不使用公開圖片。</small></label
        ><label class="field"
          >狀態<select v-model="form.status">
            <option value="draft">草稿</option>
            <option value="published">已發布</option>
          </select></label
        ><label class="field"
          >排序<input v-model.number="form.sort_order" type="number" /></label
        ><label v-if="form.visibility !== 'groups'" class="field"
          >公開圖片<input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="
              image = ($event.target as HTMLInputElement).files?.[0] || null
            "
        /></label>
      </div>
      <label class="field">摘要<textarea v-model="form.summary" /></label
      ><OrganizationEditor
        v-if="form.kind === 'page' && form.slug === 'organization'"
        v-model="form.metadata"
      />
      ><label class="field">本文</label
      ><ClientOnly
        ><RichTextEditor
          :key="form.visibility"
          v-model="form.body_html"
          :allow-images="form.visibility !== 'groups'"
          @uploading="imageUploading = $event"
      /></ClientOnly>
      <details class="card">
        <summary>文章預覽</summary>
        <ClientOnly><RichTextPreview :html="form.body_html" /></ClientOnly>
      </details>
      <details
        v-if="form.kind === 'page' && form.slug === 'organization'"
        class="card"
      >
        <summary>組織與同工預覽</summary>
        <OrganizationOverview :metadata="form.metadata" />
      </details>
      <section
        v-if="
          editing &&
          form.kind === 'news' &&
          form.visibility === 'groups' &&
          form.status === 'published'
        "
        class="card broadcast-panel"
      >
        <h3>小組通知</h3>
        <p class="muted">
          通知只顯示「新的小組消息」，成員開啟後仍會重新確認小組資格。
        </p>
        <button
          type="button"
          class="button"
          :disabled="broadcastPending"
          @click="broadcast"
        >
          {{ broadcastPending ? "發送中…" : "發送小組通知" }}
        </button>
        <p v-if="broadcasts.length" class="muted">
          最近發送：{{ broadcasts[0].recipient_count }} 位收件人
        </p>
      </section>
      <p v-if="success" class="notice">{{ success }}</p>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button" :disabled="pending || imageUploading">
        {{ pending || imageUploading ? "儲存中…" : "儲存" }}
      </button>
    </form>
  </div>
</template>
<style scoped>
.content-sections {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 20px 0;
}
.content-section {
  display: flex;
  align-items: center;
  gap: 12px;
  text-align: left;
  padding: 18px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: var(--paper);
  color: var(--ink);
  cursor: pointer;
  font: inherit;
}
.content-section.selected {
  border-color: var(--pine);
  background: #e8eee8;
  box-shadow: inset 0 0 0 1px var(--pine);
}
.content-section span {
  flex: 1;
  min-width: 0;
}
.content-section strong,
.content-section small {
  display: block;
}
.content-section small {
  margin-top: 5px;
  color: var(--muted);
  font-size: 12px;
}
.content-section b {
  font-size: 22px;
}
.content-list-title {
  font-size: 21px;
}
@media (max-width: 760px) {
  .content-sections {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .content-section {
    padding: 12px 16px;
    min-height: 64px;
  }
}
</style>
