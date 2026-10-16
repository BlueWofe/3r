<script setup lang="ts">
definePageMeta({ layout: "app" });
const tab = ref("products");
const { can } = useAuth();
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
      <h1>產品與運費</h1>
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
  <nav class="tabs" aria-label="產品管理分類"><button type="button" :class="{selected: tab === 'products'}" @click="tab = 'products'">產品</button><button v-if="can('content.update.all') || can('settings.manage.all')" type="button" :class="{selected: tab === 'shipping'}" @click="tab = 'shipping'">運費設定</button></nav>
  <div v-show="tab === 'shipping'"><ShippingSettings /></div>
  <section v-show="tab === 'products'">
  <div class="workspace-summary-grid"><article class="card"><p>產品總數</p><strong>{{ rows.length }} 筆</strong></article><article class="card"><p>已發布</p><strong>{{ rows.filter(p => p.status === 'published').length }} 筆</strong></article><article class="card"><p>草稿</p><strong>{{ rows.filter(p => p.status === 'draft').length }} 筆</strong></article></div>
  <div v-if="error" class="notice">{{ error }}</div>
  <div v-if="loading" class="empty" role="status">載入產品中…</div>
  <div v-else-if="!rows.length" class="empty">尚無食品展示。</div>
  <div v-else class="product-admin-list">
    <article v-for="p in rows" :key="p.id" class="card product-admin-card">
      <div class="product-admin-heading"><img v-if="p.image_id" :src="`/api/v1/files/${p.image_id}/download`" :alt="`${p.title} 圖片`" class="admin-thumb" /><div v-else class="product-admin-icon"><NavIcon name="box" /></div><div><h3>{{ p.title }}</h3><span class="status">{{ p.category || '未分類' }}</span> <span :class="['status', p.status]">{{ p.status === 'published' ? '已發布' : '草稿' }}</span></div></div>
      <dl class="product-admin-facts"><div><dt>規格</dt><dd>{{ p.metadata?.variants?.length || 0 }} 組</dd></div><div><dt>狀態</dt><dd>{{ p.status === 'published' ? '已發布' : '草稿' }}</dd></div><div><dt>排序</dt><dd>{{ p.sort_order }}</dd></div></dl>
      <button class="button ghost" @click="editing = p; open = true">編輯產品</button>
    </article>
  </div>
  </section>
  <ProductEditor
    v-if="open"
    :product="editing"
    @close="open = false"
    @saved="load"
  />
</template>

<style scoped>
.product-admin-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:24px; } .product-admin-heading { display:flex; align-items:center; gap:16px; margin-bottom:20px; } .product-admin-heading h3 { margin:0 0 8px; } .product-admin-icon { width:72px; height:72px; display:grid; place-items:center; border-radius:16px; background:var(--r3-sage); flex:none; } .admin-thumb { width:72px; height:72px; object-fit:cover; border-radius:16px; } .product-admin-facts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:0 0 20px; } .product-admin-facts > div { padding:16px; background:var(--r3-sage); border-radius:16px; } .product-admin-facts dt { font-size:13px; color:var(--r3-muted); } .product-admin-facts dd { margin:8px 0 0; } @media(max-width:760px) { .product-admin-list { grid-template-columns:minmax(0,1fr); } }
</style>
