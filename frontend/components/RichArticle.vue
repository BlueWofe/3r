<script setup lang="ts">
defineProps<{ html?: string | null; text?: string | null }>();
</script>

<template>
  <div v-if="html" class="rich-article rich-article-media" v-html="html" />
  <p v-else class="rich-article rich-article--legacy">{{ text || "" }}</p>
</template>
