<script setup lang="ts">
const props = defineProps<{ assignment: any; inlinePhoto?: boolean; showMissing?: boolean }>();
const { user, can } = useAuth();
const photoOpen = ref(false);
const photoError = ref(false);
const attendance = computed(() => props.assignment.attendance);
const label = computed(() => {
  if (!attendance.value) return "";
  if (attendance.value.present === false) return "管理更正：未出席";
  if (attendance.value.kind === "admin_adjustment") return "管理補登／更正：出席";
  return attendance.value.kind === "late_check_in" ? "已補簽" : "已簽到";
});
const status = computed(() => ({ assigned: "已指派", leave: "已請假", replaced: "已替換" })[props.assignment.status as "assigned" | "leave" | "replaced"] || props.assignment.status);
const photoUrl = computed(() => `/api/v1/files/${attendance.value?.photo_id}/download`);
const canViewPhoto = computed(() => can("attendance.update.all") || (props.assignment.teacher_id === user.value?.id && can("attendance.create.own")));
</script>
<template>
  <div class="attendance-summary" data-testid="attendance-summary">
    <span>{{ assignment.teacher?.name || "同工" }}：{{ status }}</span>
    <template v-if="attendance">
      <strong> · {{ label }}</strong>
      <span v-if="attendance.at" class="attendance-time">{{ taipeiDateTime(attendance.at) }}</span>
      <template v-if="attendance.photo_id && canViewPhoto">
        <img v-if="inlinePhoto && !photoError" class="attendance-thumbnail" :src="photoUrl" data-testid="attendance-photo-thumbnail" alt="簽到照片" @error="photoError = true" />
        <p v-else-if="inlinePhoto && photoError" class="muted">簽到照片目前無法顯示。</p>
        <button v-else class="button ghost" data-testid="attendance-photo-view" @click="photoError = false; photoOpen = true">查看簽到照片</button>
      </template>
    </template>
    <strong v-else-if="showMissing"> · 未簽到</strong>
    <div v-if="photoOpen" class="modal" @click.self="photoOpen = false" @keydown.esc="photoOpen = false">
      <section class="dialog" role="dialog" aria-modal="true" aria-label="簽到照片">
        <div class="workhead"><h2>簽到照片</h2><button class="button ghost" @click="photoOpen = false">關閉</button></div>
        <p v-if="photoError" role="alert" class="error">無法載入照片，請確認權限或稍後再試。</p>
        <img v-else :src="photoUrl" alt="簽到照片" @error="photoError = true" />
      </section>
    </div>
  </div>
</template>
<style scoped>
.attendance-summary { overflow-wrap: anywhere; }
.attendance-time { display: block; color: var(--muted); margin-top: 4px; }
.attendance-summary > .button { margin-top: 8px; }
img { display: block; max-width: 100%; height: auto; max-height: 70dvh; object-fit: contain; margin: auto; }
.attendance-thumbnail { width: 220px; height: 160px; object-fit: contain; margin: 8px 0 0; background: #f2f1eb; border-radius: 8px; }
.modal { z-index: 102; }
</style>
