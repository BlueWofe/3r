<script setup lang="ts">
import Image from "@tiptap/extension-image";
import StarterKit from "@tiptap/starter-kit";
import { EditorContent, useEditor } from "@tiptap/vue-3";

const props = withDefaults(
  defineProps<{ modelValue: string; allowImages?: boolean }>(),
  { allowImages: true },
);
const emit = defineEmits<{
  "update:modelValue": [value: string];
  uploading: [value: boolean];
}>();
const imageInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref("");
// A v-model update returns through props on the next render. It must not be
// treated as an external replacement while Tiptap is still editing.
const emittedHtml = new Set<string>();
const editor = useEditor({
  content: props.modelValue,
  immediatelyRender: false,
  extensions: [
    StarterKit.configure({
      link: { openOnClick: false },
      heading: { levels: [2, 3] },
    }),
    ...(props.allowImages
      ? [Image.configure({ inline: false, allowBase64: false })]
      : []),
  ],
  editorProps: {
    attributes: {
      role: "textbox",
      "aria-label": "本文",
      "aria-multiline": "true",
    },
  },
  onUpdate: ({ editor: current }: any) => {
    const html = current.getHTML();
    emittedHtml.add(html);
    emit("update:modelValue", html);
  },
} as any);
watch(
  () => props.modelValue,
  (value) => {
    if (emittedHtml.has(value)) {
      emittedHtml.clear();
      return;
    }
    emittedHtml.clear();
    if (editor.value && value !== editor.value.getHTML())
      editor.value.commands.setContent(value, { emitUpdate: false });
  },
);
onBeforeUnmount(() => editor.value?.destroy());
function setLink() {
  const url = window.prompt("輸入連結網址");
  if (url)
    editor.value
      ?.chain()
      .focus()
      .extendMarkRange("link")
      .setLink({ href: url })
      .run();
}
async function uploadImage(event: Event) {
  if (!props.allowImages) return;
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  uploadError.value = "";
  if (!file) return;
  if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
    uploadError.value = "圖片僅接受 JPEG、PNG 或 WebP 格式。";
    return;
  }
  uploading.value = true;
  emit("uploading", true);
  try {
    const payload = new FormData();
    payload.append("file", file);
    payload.append("visibility", "public");
    payload.append("title", file.name);
    const uploaded = await api<any>("/files", {
      method: "POST",
      body: payload,
    });
    editor.value
      ?.chain()
      .focus()
      .setImage({
        src: `/api/v1/files/${uploaded.id}/download`,
        alt: file.name,
      })
      .run();
  } catch (caught: any) {
    uploadError.value = caught.message || "圖片上傳失敗，請稍後再試。";
  } finally {
    uploading.value = false;
    emit("uploading", false);
    input.value = "";
  }
}
</script>
<template>
  <div class="rich-editor">
    <!-- Keep the Tiptap selection while clicking formatting buttons. -->
    <div class="rich-toolbar" aria-label="本文格式工具列" @mousedown.prevent>
      <button
        type="button"
        aria-label="粗體"
        :class="{ active: editor?.isActive('bold') }"
        @click="editor?.chain().focus().toggleBold().run()"
      >
        粗體
      </button>
      <button
        type="button"
        aria-label="斜體"
        :class="{ active: editor?.isActive('italic') }"
        @click="editor?.chain().focus().toggleItalic().run()"
      >
        斜體
      </button>
      <button
        type="button"
        aria-label="標題二"
        :class="{ active: editor?.isActive('heading', { level: 2 }) }"
        @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        標題
      </button>
      <button
        type="button"
        aria-label="項目清單"
        :class="{ active: editor?.isActive('bulletList') }"
        @click="editor?.chain().focus().toggleBulletList().run()"
      >
        清單
      </button>
      <button
        type="button"
        aria-label="引言"
        :class="{ active: editor?.isActive('blockquote') }"
        @click="editor?.chain().focus().toggleBlockquote().run()"
      >
        引言
      </button>
      <button type="button" aria-label="插入連結" @click="setLink">連結</button>
      <button
        type="button"
        aria-label="復原"
        :disabled="!editor?.can().undo()"
        @click="editor?.chain().focus().undo().run()"
      >
        復原
      </button>
      <button
        type="button"
        aria-label="重做"
        :disabled="!editor?.can().redo()"
        @click="editor?.chain().focus().redo().run()"
      >
        重做
      </button>
      <template v-if="props.allowImages">
        <button
          type="button"
          :disabled="uploading"
          @click="imageInput?.click()"
        >
          {{ uploading ? "上傳中…" : "插入圖片" }}
        </button>
        <input
          ref="imageInput"
          class="visually-hidden"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          @change="uploadImage"
        />
      </template>
    </div>
    <EditorContent :editor="editor" />
    <p v-if="uploadError" class="error">{{ uploadError }}</p>
  </div>
</template>
