<script setup lang="ts">
const props = defineProps<{ product?: any | null }>();
const emit = defineEmits(["close", "saved"]);
const p = props.product;
const copy = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const form = reactive<any>({
  title: p?.title || "",
  slug: p?.slug || "",
  summary: p?.summary || "",
  body: p?.body || "",
  category: p?.category || "",
  status: p?.status || "draft",
  sort_order: p?.sort_order ?? 0,
  image_id: p?.image_id || null,
  metadata: {
    unit: p?.metadata?.unit || "份",
    currency: "TWD",
    gallery_ids: copy(p?.metadata?.gallery_ids || []),
    spec_axes: copy(p?.metadata?.spec_axes || []),
    variants: copy(
      p?.metadata?.variants || [
        {
          id: newId(),
          sku: "",
          options: [],
          price: 0,
          stock: 0,
          active: true,
          wholesale: [],
        },
      ],
    ),
    ingredients: p?.metadata?.ingredients || "",
    allergens: p?.metadata?.allergens || "",
    net_weight: p?.metadata?.net_weight || "",
    shelf_life: p?.metadata?.shelf_life || "",
    storage: p?.metadata?.storage || "",
    origin: p?.metadata?.origin || "",
  },
});
const image = ref<File | null>(null);
const imagePreview = ref("");
const galleryFiles = ref<File[]>([]);
const galleryPreviews = ref<string[]>([]);
const saving = ref(false);
onUnmounted(() =>
  galleryPreviews.value.forEach((url) => URL.revokeObjectURL(url)),
);
const { error, run } = useApiError();
function selectGallery(event: Event) {
  galleryPreviews.value.forEach((url) => URL.revokeObjectURL(url));
  galleryFiles.value = Array.from(
    (event.target as HTMLInputElement).files || [],
  ).slice(0, 10);
  galleryPreviews.value = galleryFiles.value.map((file) =>
    URL.createObjectURL(file),
  );
}
function selectMainImage(event: Event) {
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value);
  image.value = (event.target as HTMLInputElement).files?.[0] || null;
  imagePreview.value = image.value ? URL.createObjectURL(image.value) : "";
}
function removeSavedGallery(index: string | number) {
  form.metadata.gallery_ids.splice(Number(index), 1);
}
function axis() {
  if (form.metadata.spec_axes.length < 2)
    form.metadata.spec_axes.push({ name: "規格名稱", options: [] });
}
function variant() {
  form.metadata.variants.push({
    id: newId(),
    sku: "",
    options: [],
    price: 0,
    stock: 0,
    active: true,
    wholesale: [],
  });
}
function tier(v: any) {
  v.wholesale.push({ min_quantity: 2, unit_price: v.price });
}
async function save() {
  if (saving.value) return;
  if (form.metadata.gallery_ids.length + galleryFiles.value.length > 10) {
    error.value = "圖片集最多 10 張，請先移除多餘圖片。";
    return;
  }
  saving.value = true;
  try {
    const body = copy(form);
    if (image.value) {
      const fd = new FormData();
      fd.append("file", image.value);
      fd.append("visibility", "public");
      fd.append("title", image.value.name);
      body.image_id = (
        await run(() => api<any>("/files", { method: "POST", body: fd }))
      ).id;
    }
    if (galleryFiles.value.length) {
      const uploaded = await Promise.all(
        galleryFiles.value.slice(0, 10).map((file) => {
          const fd = new FormData();
          fd.append("file", file);
          fd.append("visibility", "public");
          fd.append("title", file.name);
          return run(() => api<any>("/files", { method: "POST", body: fd }));
        }),
      );
      body.metadata.gallery_ids = [
        ...(body.metadata.gallery_ids || []),
        ...uploaded.map((file: any) => file.id),
      ].slice(0, 10);
    }
    await run(() =>
      api(p ? `/products/${p.id}` : "/products", {
        method: p ? "PUT" : "POST",
        body,
      }),
    );
    emit("saved");
    emit("close");
  } catch {
    // useApiError already exposes the actionable API error in the form.
  } finally {
    saving.value = false;
  }
}
</script>
<template>
  <div class="modal">
    <form class="dialog form" @submit.prevent="save">
      <div class="workhead">
        <h2>{{ product ? "編輯" : "新增" }}食品展示</h2>
        <button type="button" class="button ghost" @click="emit('close')">
          關閉
        </button>
      </div>
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field">名稱<input v-model="form.title" required /></label
        ><label class="field"
          >網址代稱<input v-model="form.slug" required /></label
        ><label class="field">分類<input v-model="form.category" /></label
        ><label class="field"
          >排序<input v-model.number="form.sort_order" type="number" /></label
        ><label class="field"
          >狀態<select v-model="form.status">
            <option>draft</option>
            <option>published</option>
          </select></label
        ><label class="field"
          >主圖片<input type="file" accept="image/*" @change="selectMainImage"
        /></label>
        <label class="field"
          >展示單位<input
            v-model="form.metadata.unit"
            placeholder="例如：份、盒、包"
        /></label>
        <label class="field"
          >圖片集（最多 10 張）<input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            @change="selectGallery"
        /></label>
      </div>
      <figure
        v-if="imagePreview || form.image_id"
        class="card product-editor-primary"
      >
        <a
          :href="imagePreview || `/api/v1/files/${form.image_id}/download`"
          target="_blank"
          rel="noopener"
        >
          <img
            :src="imagePreview || `/api/v1/files/${form.image_id}/download`"
            alt="主圖片預覽"
          />
        </a>
        <figcaption class="muted">主圖片預覽（點擊檢視完整圖片）</figcaption>
      </figure>
      <div
        v-if="form.metadata.gallery_ids?.length || galleryFiles.length"
        class="muted"
      >
        已儲存圖片 {{ form.metadata.gallery_ids?.length || 0 }} 張；本次準備上傳
        {{ galleryFiles.length }} 張。
      </div>
      <div
        v-if="form.metadata.gallery_ids?.length || galleryPreviews.length"
        class="grid responsive-two"
        style="grid-template-columns: repeat(3, 1fr)"
      >
        <figure
          v-for="(id, i) in form.metadata.gallery_ids"
          :key="id"
          class="card"
        >
          <img
            :src="`/api/v1/files/${id}/download`"
            alt="已儲存的商品圖片"
            style="width: 100%; height: 110px; object-fit: cover"
          />
          <button
            type="button"
            class="button danger"
            @click="removeSavedGallery(i)"
          >
            移除
          </button>
        </figure>
        <figure v-for="(url, i) in galleryPreviews" :key="url" class="card">
          <img
            :src="url"
            :alt="`準備上傳的商品圖片 ${i + 1}`"
            style="width: 100%; height: 110px; object-fit: cover"
          />
          <figcaption class="muted">準備上傳</figcaption>
        </figure>
      </div>
      <label class="field"
        >摘要<textarea v-model="form.summary"></textarea></label
      ><label class="field"
        >介紹<textarea v-model="form.body"></textarea>
      </label>
      <div class="card">
        <div class="workhead">
          <h3>規格軸（最多兩組）</h3>
          <button type="button" class="button ghost" @click="axis">
            新增規格軸
          </button>
        </div>
        <div
          v-for="(a, i) in form.metadata.spec_axes"
          :key="i"
          class="grid responsive-two"
          style="grid-template-columns: 1fr 2fr"
        >
          <label class="field"
            >規格名稱<input v-model="a.name" placeholder="例如：口味" /></label
          ><label class="field"
            >選項（逗號分隔）<input
              :value="a.options.join(',')"
              placeholder="選項，以逗號分隔"
              @input="
                a.options = ($event.target as HTMLInputElement).value
                  .split(',')
                  .filter(Boolean)
              " /></label
          ><button
            type="button"
            class="button danger"
            @click="form.metadata.spec_axes.splice(i, 1)"
          >
            移除
          </button>
        </div>
      </div>
      <div class="card">
        <div class="workhead">
          <h3>規格 SKU、庫存與大量優惠</h3>
          <button type="button" class="button ghost" @click="variant">
            新增規格
          </button>
        </div>
        <article
          v-for="(v, i) in form.metadata.variants"
          :key="v.id"
          class="card"
        >
          <div
            class="grid responsive-two"
            style="grid-template-columns: 1fr 1fr"
          >
            <label class="field">SKU<input v-model="v.sku" required /></label
            ><label class="field"
              >規格選項（依軸順序）<input
                :value="v.options.join(',')"
                @input="
                  v.options = ($event.target as HTMLInputElement).value
                    .split(',')
                    .filter(Boolean)
                " /></label
            ><label class="field"
              >單價<input
                v-model.number="v.price"
                type="number"
                min="0"
                step="0.01"
                required /></label
            ><label class="field"
              >庫存<input
                v-model.number="v.stock"
                type="number"
                min="0"
                required
            /></label>
          </div>
          <label><input v-model="v.active" type="checkbox" /> 啟用此規格</label>
          <div v-for="(t, j) in v.wholesale" :key="j" class="toolbar">
            <label class="field"
              >數量門檻<input
                v-model.number="t.min_quantity"
                type="number"
                min="2"
                placeholder="數量門檻" /></label
            ><label class="field"
              >優惠單價<input
                v-model.number="t.unit_price"
                type="number"
                min="0"
                step="0.01"
                placeholder="優惠單價" /></label
            ><button
              type="button"
              class="button danger"
              @click="v.wholesale.splice(j, 1)"
            >
              移除
            </button>
          </div>
          <div class="actions">
            <button type="button" class="button ghost" @click="tier(v)">
              新增大量優惠</button
            ><button
              type="button"
              class="button danger"
              @click="form.metadata.variants.splice(i, 1)"
            >
              移除此規格
            </button>
          </div>
        </article>
      </div>
      <div class="card">
        <h3>食品資料</h3>
        <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
          <label class="field"
            >成分<textarea
              v-model="form.metadata.ingredients"
            ></textarea></label
          ><label class="field"
            >過敏原<textarea
              v-model="form.metadata.allergens"
            ></textarea></label
          ><label class="field"
            >淨重<input v-model="form.metadata.net_weight" /></label
          ><label class="field"
            >保存期限<input v-model="form.metadata.shelf_life" /></label
          ><label class="field"
            >保存方式<input v-model="form.metadata.storage" /></label
          ><label class="field"
            >產地<input v-model="form.metadata.origin"
          /></label>
        </div>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button" :disabled="saving">
        {{ saving ? "儲存中…" : "儲存食品" }}
      </button>
    </form>
  </div>
</template>
