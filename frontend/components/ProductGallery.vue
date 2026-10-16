<script setup lang="ts">
const props = defineProps<{ title: string; mainId?: number | null; galleryIds?: number[]; slug?: string }>();
const images = computed(() => [...new Set([props.mainId, ...(props.galleryIds || [])].filter((id): id is number => Number.isInteger(id) && Number(id) > 0))]);
const current = ref(0);
const dialog = ref<HTMLDialogElement | null>(null);
const opener = ref<HTMLButtonElement | null>(null);
const opened = ref(false);
const imageUrl = (id: number | undefined) => `/api/v1/files/${id}/download`;
const thumbnails = computed(() => images.value.map((id, index) => ({ id, index })).filter(item => !props.mainId || item.id !== props.mainId));
let previousOverflow = '';
let previousPadding = '';
let scrollLocked = false;
let suppressClickUntil = 0;
let gesture: { x: number; y: number; horizontal: boolean } | null = null;
function select(index: number) { current.value = index; }
function step(direction: number) {
  if (images.value.length > 1) current.value = (current.value + direction + images.value.length) % images.value.length;
}
function touchStart(event: TouchEvent) {
  const touch = event.touches[0];
  gesture = event.touches.length === 1 && touch ? { x: touch.clientX, y: touch.clientY, horizontal: false } : null;
}
function touchMove(event: TouchEvent) {
  if (!gesture || event.touches.length !== 1) { gesture = null; return; }
  const touch = event.touches[0];
  if (!touch) return;
  const dx = Math.abs(touch.clientX - gesture.x), dy = Math.abs(touch.clientY - gesture.y);
  if (dy > 12 && dy > dx) { gesture = null; return; }
  gesture.horizontal = dx > 12 && dx > dy * 1.3;
}
function touchEnd(event: TouchEvent) {
  const touch = event.changedTouches[0];
  if (gesture && touch) {
    const dx = touch.clientX - gesture.x, dy = touch.clientY - gesture.y;
    if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy) * 1.3) {
      step(dx < 0 ? 1 : -1);
      suppressClickUntil = Date.now() + 500;
    }
  }
  gesture = null;
}
function unlockScroll() {
  if (!scrollLocked) return;
  document.body.style.overflow = previousOverflow;
  document.body.style.paddingRight = previousPadding;
  scrollLocked = false;
}
function open() {
  if (Date.now() < suppressClickUntil || !images.value.length || !dialog.value || opened.value) return;
  previousOverflow = document.body.style.overflow;
  previousPadding = document.body.style.paddingRight;
  const scrollbar = window.innerWidth - document.documentElement.clientWidth;
  if (scrollbar > 0) document.body.style.paddingRight = `${parseFloat(getComputedStyle(document.body).paddingRight) + scrollbar}px`;
  document.body.style.overflow = 'hidden';
  scrollLocked = true;
  dialog.value.showModal();
  opened.value = true;
}
function close() { dialog.value?.close(); }
function closed() {
  opened.value = false;
  unlockScroll();
  opener.value?.focus({ preventScroll: true });
}
function keydown(event: KeyboardEvent) {
  if (opened.value && event.key === 'Tab' && dialog.value) {
    const buttons = [...dialog.value.querySelectorAll<HTMLButtonElement>('button:not([disabled])')];
    const first = buttons[0], last = buttons[buttons.length - 1];
    const active = document.activeElement;
    if (first && last && (!dialog.value.contains(active) || (event.shiftKey ? active === first : active === last))) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    }
    return;
  }
  if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
    event.preventDefault();
    step(event.key === 'ArrowLeft' ? -1 : 1);
  }
}
watch(images, () => { current.value = 0; if (opened.value) close(); });
onBeforeUnmount(() => { dialog.value?.close(); unlockScroll(); });
</script>

<template>
  <div class="product-gallery" data-testid="product-gallery" @keydown="keydown">
    <div v-if="images.length" class="gallery-stage" data-testid="product-gallery-stage">
      <button ref="opener" type="button" class="gallery-open" data-testid="product-gallery-open" aria-label="放大商品圖片" aria-haspopup="dialog" @click="open" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend.passive="touchEnd" @touchcancel="gesture = null">
        <img class="product-detail-image" data-testid="product-gallery-main-image" :src="imageUrl(images[current])" :alt="`${title}，第 ${current + 1} 張商品圖片`" draggable="false" />
        <span class="gallery-expand">點圖放大</span>
      </button>
      <template v-if="images.length > 1">
        <button type="button" class="gallery-arrow gallery-arrow--previous" data-testid="product-gallery-prev" aria-label="上一張圖片" @click="step(-1)"><NavIcon name="chevron" /></button>
        <button type="button" class="gallery-arrow gallery-arrow--next" data-testid="product-gallery-next" aria-label="下一張圖片" @click="step(1)"><NavIcon name="chevron" /></button>
      </template>
      <span class="gallery-count" data-testid="product-gallery-count" role="status" aria-live="polite">{{ current + 1 }} / {{ images.length }}</span>
    </div>
    <PastryIllustration v-else-if="['classic-yolk-pastry', 'taro-yolk-pastry'].includes(slug || '')" :flavor="slug === 'taro-yolk-pastry' ? 'taro' : 'red-bean'" />
    <div v-else class="product-fallback" aria-label="尚無商品圖片">🍞</div>
    <div v-if="thumbnails.length" class="product-gallery-thumbnails" data-testid="product-gallery-thumbnails" aria-label="選擇商品圖片">
      <button v-for="image in thumbnails" :key="image.id" type="button" class="gallery-thumbnail" :class="{ 'is-current': current === image.index }" :data-testid="`product-gallery-thumbnail-${image.index}`" :aria-label="`檢視第 ${image.index + 1} 張商品圖片`" :aria-pressed="current === image.index" @click="select(image.index)">
        <img :src="imageUrl(image.id)" :alt="`${title}商品附圖 ${image.index + 1}`" class="product-gallery-image" draggable="false" />
      </button>
    </div>
    <dialog ref="dialog" class="product-lightbox" data-testid="product-lightbox" aria-label="商品圖片檢視" @close="closed" @click="($event.target === dialog) && close()">
      <div class="lightbox-content">
        <div class="lightbox-toolbar"><p>{{ title }}</p><button type="button" class="lightbox-close" data-testid="product-lightbox-close" aria-label="關閉圖片檢視" autofocus @click="close">關閉 <span aria-hidden="true">×</span></button></div>
        <div class="lightbox-stage" @touchstart.passive="touchStart" @touchmove.passive="touchMove" @touchend.passive="touchEnd" @touchcancel="gesture = null">
          <img v-if="images.length" :src="imageUrl(images[current])" :alt="`${title}，第 ${current + 1} 張商品圖片`" data-testid="product-lightbox-image" draggable="false" />
          <template v-if="images.length > 1">
            <button type="button" class="gallery-arrow gallery-arrow--previous" data-testid="product-lightbox-prev" aria-label="上一張大圖" @click="step(-1)"><NavIcon name="chevron" /></button>
            <button type="button" class="gallery-arrow gallery-arrow--next" data-testid="product-lightbox-next" aria-label="下一張大圖" @click="step(1)"><NavIcon name="chevron" /></button>
          </template>
        </div>
        <p class="lightbox-count" data-testid="product-lightbox-count" role="status" aria-live="polite">{{ current + 1 }} / {{ images.length }}<span v-if="images.length > 1"> · 左右滑動或使用方向鍵瀏覽</span></p>
      </div>
    </dialog>
  </div>
</template>

<style scoped>
.product-gallery { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.gallery-stage { position: relative; min-width: 0; }
.gallery-open { display: block; width: 100%; padding: 0; border: 0; border-radius: 24px; background: #eee3cb; cursor: zoom-in; touch-action: pan-y pinch-zoom; overflow: hidden; }
.product-gallery .product-detail-image { display: block; width: 100%; height: 420px; max-height: 420px; object-fit: cover; }
.gallery-open:focus-visible, .gallery-thumbnail:focus-visible, .gallery-arrow:focus-visible, .lightbox-close:focus-visible { outline: 3px solid #ab7c25; outline-offset: 4px; }
.gallery-expand, .gallery-count { position: absolute; bottom: 14px; border-radius: 18px; padding: 5px 12px; background: rgb(19 46 38 / 85%); color: white; font-size: 13px; pointer-events: none; }
.gallery-expand { left: 14px; }
.gallery-count { right: 14px; }
.gallery-arrow { position: absolute; top: 50%; transform: translateY(-50%); display: grid; place-items: center; width: 44px; height: 44px; min-height: 44px; padding: 10px; border: 1px solid #dce3db; border-radius: 50%; background: rgb(255 255 255 / 95%); color: #173c30; cursor: pointer; box-shadow: 0 2px 12px #0002; }
.gallery-arrow--previous { left: 12px; }
.gallery-arrow--next { right: 12px; }
.gallery-arrow--previous :deep(svg) { transform: rotate(90deg); }
.gallery-arrow--next :deep(svg) { transform: rotate(-90deg); }
.product-gallery-thumbnails { display: grid; width: 100%; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.gallery-thumbnail { min-width: 0; padding: 0; border: 0; border-radius: 16px; position: relative; background: transparent; cursor: pointer; overflow: hidden; }
.gallery-thumbnail.is-current::after { content: ""; position: absolute; inset: 0; border-radius: 16px; box-shadow: inset 0 0 0 3px #245b44, inset 0 0 0 5px #e9c579; pointer-events: none; }
.product-gallery .product-gallery-image { display: block; width: 100%; height: 96px; border-radius: 16px; object-fit: cover; }
.product-lightbox { width: min(1080px, calc(100vw - 32px)); max-width: none; max-height: calc(100dvh - 32px); margin: auto; padding: 20px; border: 0; border-radius: 20px; background: #faf8f2; color: #173c30; overflow: auto; }
.product-lightbox::backdrop { background: rgb(8 20 16 / 85%); }
.lightbox-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.lightbox-toolbar p { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.lightbox-close { flex-shrink: 0; min-height: 44px; padding: 8px 14px; border: 1px solid #bdc8bd; border-radius: 24px; background: white; color: #173c30; cursor: pointer; }
.lightbox-close span { font-size: 22px; margin-left: 8px; }
.lightbox-stage { position: relative; touch-action: pan-y pinch-zoom; }
.lightbox-stage > img { display: block; width: 100%; height: min(65dvh, 720px); object-fit: contain; user-select: none; }
.lightbox-count { margin: 14px 0 0; text-align: center; font-size: 14px; }
@media (max-width: 760px) {
  .product-gallery .product-detail-image { height: 280px; max-height: 280px; }
  .product-gallery .product-gallery-image { height: 80px; }
  .product-lightbox { padding: 12px; width: calc(100vw - 24px); max-height: calc(100dvh - 24px); }
  .lightbox-toolbar { gap: 8px; font-size: 15px; }
  .lightbox-count span { display: block; margin-top: 4px; }
}
</style>
