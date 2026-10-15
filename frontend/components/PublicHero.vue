<script setup lang="ts">
defineProps<{
  eyebrow?: string;
  title: string;
  description?: string;
  photo?: boolean;
}>();
</script>
<template>
  <section :class="['public-page-hero', { 'public-page-hero--photo': photo }]">
    <div class="container">
      <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
      <h1>{{ title }}</h1>
      <p v-if="description" class="hero-description">{{ description }}</p>
      <slot />
    </div>
  </section>
</template>
