<script setup lang="ts">
import StarterKit from "@tiptap/starter-kit";
import Image from "@tiptap/extension-image";
import { EditorContent, useEditor } from "@tiptap/vue-3";

const props = defineProps<{ html: string }>();
const editor = useEditor({
  content: props.html,
  editable: false,
  immediatelyRender: false,
  extensions: [StarterKit, Image],
} as any);
watch(
  () => props.html,
  (value) => editor.value?.commands.setContent(value, { emitUpdate: false }),
);
onBeforeUnmount(() => editor.value?.destroy());
</script>
<template>
  <div class="rich-article"><EditorContent :editor="editor" /></div>
</template>
