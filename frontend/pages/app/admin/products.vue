<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]),
  open = ref(false),
  editing = ref<any>(null),
  loading = ref(false),
  error = ref("");
async function load() {
  loading.value = true;
  try {
    rows.value = (await api<any>("/products")).data || [];
    error.value = "";
  } catch (e: any) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">PRODUCT CATALOG</p>
      <h1>食品展示管理</h1>
      <p class="muted">
        管理展示資料、規格 SKU、庫存與大量優惠；本系統沒有訂單或結帳。
      </p>
    </div>
    <button
      class="button"
      @click="
        editing = null;
        open = true;
      "
    >
      新增食品
    </button>
  </div>
  <div v-if="error" class="notice">{{ error }}</div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>名稱</th>
          <th>分類</th>
          <th>規格</th>
          <th>狀態</th>
          <th>排序</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in rows" :key="p.id">
          <td>{{ p.title }}</td>
          <td>{{ p.category }}</td>
          <td>{{ p.metadata?.variants?.length || 0 }} 組</td>
          <td>{{ p.status }}</td>
          <td>{{ p.sort_order }}</td>
          <td>
            <button
              class="button ghost"
              @click="
                editing = p;
                open = true;
              "
            >
              編輯
            </button>
          </td>
        </tr>
        <tr v-if="!rows.length && !loading">
          <td colspan="6" class="empty">尚無食品展示。</td>
        </tr>
      </tbody>
    </table>
  </div>
  <ProductEditor
    v-if="open"
    :product="editing"
    @close="open = false"
    @saved="load"
  />
</template>
