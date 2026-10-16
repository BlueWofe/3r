<script setup lang="ts">
const notice = useState("save-notice", () => ({ serial: 0, message: "" }));
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
  () => notice.value.serial,
  () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      notice.value.message = "";
    }, 5000);
  },
);
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
  <div
    class="save-notice-container"
    role="status"
    aria-live="polite"
    aria-atomic="true"
  >
    <div v-if="notice.message" class="save-notice">
      <span aria-hidden="true">✓</span> {{ notice.message
      }}<button
        type="button"
        aria-label="關閉更新提示"
        @click="notice.message = ''"
      >
        ×
      </button>
    </div>
  </div>
</template>
<style scoped>
.save-notice-container {
  position: fixed;
  right: 20px;
  bottom: 20px;
  z-index: 150;
  max-width: calc(100vw - 40px);
  pointer-events: none;
}
.save-notice {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--pine);
  color: white;
  padding: 8px 12px 8px 18px;
  border-radius: 12px;
  box-shadow: 0 6px 24px #0003;
  pointer-events: auto;
}
.save-notice button {
  background: transparent;
  border: 0;
  color: inherit;
  font-size: 24px;
  min-height: 44px;
  min-width: 44px;
  cursor: pointer;
}
</style>
