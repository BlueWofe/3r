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
      <h1>產品管理</h1>
      <p class="muted">
        管理產品資料、規格 SKU、庫存、大量優惠與配送設定；顧客可送出訂單，本系統不收取線上付款。
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
  <ShippingSettings />
  <div v-if="error" class="notice">{{ error }}</div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>圖片</th>
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
          <td data-label="圖片">
            <img
              v-if="p.image_id"
              class="admin-thumb"
              :src="`/api/v1/files/${p.image_id}/download`"
              :alt="`${p.title} 圖片`"
            /><span v-else class="admin-thumb placeholder" aria-label="尚無圖片"
              >—</span
            >
          </td>
          <td data-label="名稱">{{ p.title }}</td>
          <td data-label="分類">{{ p.category }}</td>
          <td data-label="規格">{{ p.metadata?.variants?.length || 0 }} 組</td>
          <td data-label="狀態">{{ p.status }}</td>
          <td data-label="排序">{{ p.sort_order }}</td>
          <td data-label="操作">
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
          <td colspan="7" class="empty">尚無食品展示。</td>
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
