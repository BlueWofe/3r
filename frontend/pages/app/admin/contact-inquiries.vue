<script setup lang="ts">
definePageMeta({ layout: "app" });
const { can } = useAuth();
const canUpdate = computed(() => can("contacts.update.all"));
const categories = [
    "監所探訪與代禱",
    "更生安置與職訓",
    "食品採購與禮盒",
    "志工加入",
    "奉獻與收據諮詢",
    "其他諮詢",
  ],
  statuses = ["new", "processing", "closed"];
const labels: Record<string, string> = {
  new: "新訊息",
  processing: "處理中",
  closed: "已結案",
};
const rows = ref<any[]>([]),
  category = ref(""),
  status = ref(""),
  q = ref(""),
  loading = ref(true),
  error = ref(""),
  success = ref(""),
  selected = ref<any>(null),
  note = ref(""),
  nextStatus = ref("new"),
  pending = ref(false);
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const params = new URLSearchParams();
    if (category.value) params.set("category", category.value);
    if (status.value) params.set("status", status.value);
    if (q.value) params.set("q", q.value);
    rows.value = (await api<any>(`/contact-inquiries?${params}`)).data || [];
  } catch (e: any) {
    error.value = e.message || "無法載入聯絡表單。";
  } finally {
    loading.value = false;
  }
}
function view(row: any) {
  selected.value = row;
  note.value = row.staff_note || "";
  nextStatus.value = row.status;
  error.value = "";
}
async function save() {
  if (!selected.value || pending.value || !canUpdate.value) return;
  pending.value = true;
  error.value = "";
  try {
    await api(`/contact-inquiries/${selected.value.id}`, {
      method: "PUT",
      body: {
        version: selected.value.version,
        status: nextStatus.value,
        staff_note: note.value,
      },
    });
    success.value = "已更新";
    selected.value = null;
    await load();
  } catch (e: any) {
    error.value = e.message || "更新失敗";
  } finally {
    pending.value = false;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">PUBLIC CONTENT</p>
      <h1>聯絡表單</h1>
      <p class="muted">訪客留下的訊息僅供獲授權同工處理。</p>
    </div>
  </div>
  <form class="toolbar" @submit.prevent="load">
    <label
      >分類<select v-model="category">
        <option value="">全部分類</option>
        <option v-for="item in categories" :key="item">{{ item }}</option>
      </select></label
    ><label
      >狀態<select v-model="status" aria-label="狀態">
        <option value="">全部狀態</option>
        <option v-for="item in statuses" :key="item" :value="item">
          {{ labels[item] }}
        </option>
      </select></label
    ><input v-model="q" placeholder="搜尋姓名、電話或訊息" /><button
      class="button"
    >
      篩選
    </button>
  </form>
  <p v-if="success" class="notice">{{ success }}</p>
  <p v-if="loading" class="empty">載入聯絡表單中…</p>
  <p v-else-if="error && !selected" class="notice">{{ error }}</p>
  <div v-else-if="!rows.length" class="card empty">
    沒有符合條件的聯絡訊息。
  </div>
  <div v-else class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>姓名</th>
          <th>分類</th>
          <th>訊息摘要</th>
          <th>狀態</th>
          <th>建立時間</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id">
          <td data-label="姓名">{{ row.name }}</td>
          <td data-label="分類">{{ row.category }}</td>
          <td data-label="訊息摘要"><span class="inquiry-preview">{{ row.message }}</span></td>
          <td data-label="狀態">{{ labels[row.status] }}</td>
          <td data-label="建立時間">
            {{
              new Intl.DateTimeFormat("zh-TW", {
                timeZone: "Asia/Taipei",
                dateStyle: "medium",
                timeStyle: "short",
              }).format(new Date(row.created_at))
            }}
          </td>
          <td data-label="操作">
            <button class="button ghost" @click="view(row)">{{ canUpdate ? "查看與處理" : "查看" }}</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div v-if="selected" class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>聯絡訊息</h2>
        <button
          class="button ghost"
          type="button"
          :disabled="pending"
          @click="selected = null"
        >
          關閉
        </button>
      </div>
      <dl class="inquiry">
        <dt>姓名</dt>
        <dd>{{ selected.name }}</dd>
        <dt>電話</dt>
        <dd>{{ selected.phone }}</dd>
        <dt>電子郵件</dt>
        <dd>{{ selected.email || "—" }}</dd>
        <dt>分類</dt>
        <dd>{{ selected.category }}</dd>
        <dt>訊息</dt>
        <dd class="preserve">{{ selected.message }}</dd>
        <dt>處理人</dt>
        <dd>{{ selected.handled_by_name || "尚未指派" }}</dd>
      </dl>
      <label v-if="canUpdate" class="field"
        >處理狀態<select v-model="nextStatus">
          <option v-for="item in statuses" :key="item" :value="item">
            {{ labels[item] }}
          </option>
        </select></label
      ><label v-if="canUpdate" class="field">同工備註<textarea v-model="note" maxlength="10000" /></label>
      <p v-if="!canUpdate" class="preserve">同工備註：{{ selected.staff_note || "—" }}</p>
      <p v-if="error" class="error">{{ error }}</p>
      <button v-if="canUpdate" class="button" :disabled="pending">
        {{ pending ? "儲存中…" : "儲存處理狀態" }}
      </button>
    </form>
  </div>
</template>
<style scoped>
.inquiry {
  display: grid;
  grid-template-columns: 100px 1fr;
  gap: 10px;
  margin: 0;
}
.inquiry dt {
  font-weight: 700;
}
.inquiry dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.preserve {
  white-space: pre-wrap;
}
.inquiry-preview {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
  overflow-wrap: anywhere;
  max-width: 420px;
}
@media (max-width: 760px) {
  .inquiry {
    grid-template-columns: 1fr;
  }
  .inquiry dt {
    margin-top: 8px;
  }
}
</style>
