<script setup lang="ts">
const metadata = defineModel<Record<string, any>>({ required: true });
const groups = [
  {
    key: "organization_levels",
    title: "組織層級",
    noun: "層級",
    fields: [{ key: "value", label: "名稱" }],
  },
  {
    key: "departments",
    title: "服務部門",
    noun: "部門",
    fields: [
      { key: "name", label: "名稱" },
      { key: "description", label: "說明" },
    ],
  },
  {
    key: "team",
    title: "同工介紹",
    noun: "同工",
    fields: [
      { key: "name", label: "姓名" },
      { key: "role", label: "職務" },
      { key: "bio", label: "簡介" },
    ],
  },
];
const rows = (key: string): any[] =>
  Array.isArray(metadata.value[key]) ? metadata.value[key] : [];
const value = (row: any, field: string) =>
  field === "value" ? (typeof row === "string" ? row : "") : row?.[field] || "";
function update(key: string, index: number, field: string, text: string) {
  const items = [...rows(key)];
  items[index] =
    field === "value" ? text : { ...(items[index] || {}), [field]: text };
  metadata.value = { ...metadata.value, [key]: items };
}
function add(key: string) {
  metadata.value = {
    ...metadata.value,
    [key]: [
      ...rows(key),
      key === "organization_levels"
        ? ""
        : key === "team"
          ? { name: "", role: "", bio: "" }
          : { name: "", description: "" },
    ],
  };
}
function remove(key: string, index: number) {
  metadata.value = {
    ...metadata.value,
    [key]: rows(key).filter((_, rowIndex) => rowIndex !== index),
  };
}
</script>
<template>
  <section class="organization-editor" aria-label="組織與同工資料">
    <h3>組織與同工資料</h3>
    <p class="muted">
      由上到下安排組織層級；各項目可新增、修改或移除。發布後會出現在「關於我們」。
    </p>
    <div v-for="group in groups" :key="group.key" class="editor-group">
      <div class="editor-heading">
        <h4>{{ group.title }}</h4>
        <button
          type="button"
          class="button ghost"
          :disabled="rows(group.key).length >= 20"
          @click="add(group.key)"
        >
          新增{{ group.noun }}
        </button>
      </div>
      <article
        v-for="(row, index) in rows(group.key)"
        :key="index"
        class="editor-row"
      >
        <div class="row-title">
          <strong>{{ group.noun }} {{ index + 1 }}</strong
          ><button
            type="button"
            class="remove-row"
            :aria-label="`移除${group.noun} ${index + 1}`"
            @click="remove(group.key, index)"
          >
            移除
          </button>
        </div>
        <label v-for="field in group.fields" :key="field.key" class="field"
          >{{ field.label
          }}<textarea
            v-if="field.key === 'bio' || field.key === 'description'"
            :aria-label="`${group.noun} ${index + 1} ${field.label}`"
            :value="value(row, field.key)"
            maxlength="2000"
            @input="
              update(
                group.key,
                index,
                field.key,
                ($event.target as HTMLTextAreaElement).value,
              )
            " /><input
            v-else
            :aria-label="`${group.noun} ${index + 1} ${field.label}`"
            :value="value(row, field.key)"
            maxlength="200"
            required
            @input="
              update(
                group.key,
                index,
                field.key,
                ($event.target as HTMLInputElement).value,
              )
            "
        /></label>
      </article>
      <p v-if="!rows(group.key).length" class="muted editor-empty">
        尚未設定{{ group.title }}，按「新增{{ group.noun }}」開始。
      </p>
    </div>
    <label class="demo-confirm"
      ><input
        type="checkbox"
        :checked="metadata.demo === true"
        @change="
          metadata = {
            ...metadata,
            demo: ($event.target as HTMLInputElement).checked,
          }
        "
      />示範資料；確認為正式資料後取消勾選。</label
    >
  </section>
</template>
<style scoped>
.organization-editor {
  padding: 24px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: #f4f6f2;
  min-width: 0;
}
.organization-editor > h3 {
  margin-top: 0;
}
.editor-group {
  margin-top: 24px;
}
.editor-heading,
.row-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.editor-heading h4 {
  font-size: 17px;
  margin: 0;
}
.editor-row {
  background: white;
  border-radius: 12px;
  padding: 16px;
  margin-top: 12px;
  display: grid;
  gap: 12px;
  min-width: 0;
}
.remove-row {
  padding: 8px 12px;
  min-height: 44px;
  border: 0;
  background: transparent;
  color: var(--danger);
  cursor: pointer;
  font: inherit;
}
.editor-empty {
  font-size: 14px;
}
.demo-confirm {
  display: flex;
  align-items: start;
  gap: 10px;
  margin-top: 24px;
  font-size: 14px;
}
.demo-confirm input {
  width: 18px;
  flex-shrink: 0;
  margin-top: 4px;
}
@media (max-width: 760px) {
  .organization-editor {
    padding: 16px;
  }
  .editor-heading .button {
    padding: 9px 12px;
  }
}
</style>
