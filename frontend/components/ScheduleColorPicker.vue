<script setup lang="ts">
const color = defineModel<string>({ default: "#3d8768" });
defineProps<{ disabled?: boolean; label?: string }>();
const palette = [
  { name: "森林綠", value: "#3d8768" },
  { name: "湖水藍", value: "#326e9c" },
  { name: "紫藤", value: "#8055a3" },
  { name: "暖橘", value: "#bb672c" },
  { name: "玫瑰", value: "#b24e70" },
  { name: "青綠", value: "#277e83" },
  { name: "金褐", value: "#967323" },
  { name: "石板灰", value: "#626e7a" },
];
</script>
<template>
  <fieldset class="color-picker" :disabled="disabled">
    <legend>{{ label || "顯示顏色" }}</legend>
    <div class="color-options">
      <button v-for="option in palette" :key="option.value" type="button"
        :aria-label="option.name" :aria-pressed="color.toLowerCase() === option.value"
        :title="option.name" @click="color = option.value">
        <span :style="{ backgroundColor: option.value }" aria-hidden="true">{{ color.toLowerCase() === option.value ? "✓" : "" }}</span>
      </button>
      <label class="custom-color">自訂<input v-model="color" type="color" aria-label="自訂顯示顏色" /></label>
    </div>
    <p class="muted color-hint">{{ disabled ? "顏色由排課管理者設定。" : "選擇常用色，或點選自訂顏色。" }}</p>
  </fieldset>
</template>
<style scoped>
.color-picker { border: 0; padding: 0; margin: 8px 0; min-width: 0; }
legend { margin-bottom: 8px; }
.color-options { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
button { width: 44px; height: 44px; display: grid; place-items: center; border: 2px solid transparent; border-radius: 12px; background: transparent; cursor: pointer; }
button[aria-pressed="true"] { border-color: var(--pine); background: #edf2eb; }
button span { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; color: white; font-size: 18px; }
button:focus-visible, input:focus-visible { outline: 2px solid var(--pine); outline-offset: 2px; }
.custom-color { display: flex; align-items: center; gap: 6px; padding-left: 8px; font-size: 14px; }
input { width: 44px; height: 44px; padding: 3px; cursor: pointer; }
.color-hint { margin: 8px 0 0; font-size: 13px; }
fieldset:disabled { opacity: .7; }
</style>
