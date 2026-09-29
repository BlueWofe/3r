<script setup lang="ts">
const props = defineProps<{
  logoUrl?: string | null;
  alt?: string;
  footer?: boolean;
}>();
</script>
<template>
  <img
    v-if="props.logoUrl"
    :class="['brand-logo', { 'brand-logo-footer': footer }]"
    :src="props.logoUrl"
    :alt="props.alt || '協會標誌'"
  /><span v-else class="seal" aria-label="協會識別章">✦</span>
</template>
