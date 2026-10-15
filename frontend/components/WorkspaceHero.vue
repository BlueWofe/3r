<script setup lang="ts">
defineProps<{ title: string; subtitle?: string; eyebrow?: string; light?: boolean }>();
</script>
<template>
  <section :class="['workspace-hero', { 'workspace-hero-light': light }]">
    <div class="workspace-hero-copy"><p v-if="eyebrow" class="workspace-hero-eyebrow">{{ eyebrow }}</p><h1>{{ title }}</h1><p v-if="subtitle" class="workspace-hero-subtitle">{{ subtitle }}</p><div v-if="$slots.actions" class="actions"><slot name="actions" /></div></div>
    <div v-if="$slots.summary" class="workspace-hero-summary"><slot name="summary" /></div>
  </section>
</template>
