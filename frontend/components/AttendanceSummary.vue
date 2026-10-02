<script setup lang="ts">
const props = withDefaults(defineProps<{ assignment: any; inlinePhoto?: boolean; showMissing?: boolean; sessionStatus?: string }>(), { showMissing: true });
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
const cancelled = computed(() => props.sessionStatus === "cancelled");
const needsCheckIn = computed(() => !cancelled.value && props.assignment.status === "assigned");
const status = computed(() => cancelled.value ? "停課" : ({ assigned: "已指派", leave: "請假", replaced: "已替換" })[props.assignment.status as "assigned" | "leave" | "replaced"] || "缺額");
const statusTone = computed(() => cancelled.value ? "cancelled" : props.assignment.status === "leave" ? "pending" : props.assignment.status === "assigned" ? "assigned" : "inactive");
const statusIcon = computed(() => props.assignment.status === "leave" ? "history" : props.assignment.status === "replaced" ? "logout" : "person");
const photoUrl = computed(() => `/api/v1/files/${attendance.value?.photo_id}/download`);
const canViewPhoto = computed(() => can("attendance.update.all") || (props.assignment.teacher_id === user.value?.id && can("attendance.create.own")));
</script>
<template>
  <div class="attendance-summary" data-testid="attendance-summary">
    <div class="attendance-heading">
      <strong class="teacher-name" data-testid="attendance-teacher-name">{{ assignment.teacher?.name || "尚未指派老師" }}：</strong>
      <span :class="['attendance-badge', statusTone]" data-testid="attendance-assignment-status">
        <svg v-if="cancelled" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="m8 8 8 8m0-8-8 8" /></svg><NavIcon v-else :name="statusIcon" />{{ status }}
      </span>
      <span v-if="attendance" :class="['attendance-badge', attendance.present === false ? 'inactive' : 'attended']" data-testid="attendance-check-in-status"><NavIcon name="shield" />{{ label }}</span>
      <span v-else-if="showMissing" :class="['attendance-badge', needsCheckIn ? 'pending' : 'inactive']" data-testid="attendance-check-in-status"><NavIcon :name="needsCheckIn ? 'history' : 'calendar'" />{{ needsCheckIn ? '未簽到' : '不需簽到' }}</span>
    </div>
    <template v-if="attendance">
      <span v-if="attendance.at" class="attendance-time">{{ taipeiDateTime(attendance.at) }}</span>
      <template v-if="attendance.photo_id && canViewPhoto">
        <img v-if="inlinePhoto && !photoError" class="attendance-thumbnail" :src="photoUrl" data-testid="attendance-photo-thumbnail" alt="簽到照片" @error="photoError = true" />
        <p v-else-if="inlinePhoto && photoError" class="muted">簽到照片目前無法顯示。</p>
        <button v-else class="button ghost" data-testid="attendance-photo-view" @click="photoError = false; photoOpen = true">查看簽到照片</button>
      </template>
    </template>
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
.attendance-heading { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }
.teacher-name { font-size: 18px; font-weight: 700; line-height: 1.45; }
.attendance-badge { display: inline-flex; align-items: center; gap: 5px; max-width: 100%; border-radius: 8px; padding: 4px 8px; font-size: 16px; font-weight: 700; line-height: 1.4; }
.attendance-badge svg { width: 18px; height: 18px; flex: 0 0 18px; }
.assigned { background: #eaf1eb; color: #244d36; }
.attended { background: #d9ecdf; color: #205c37; }
.pending { background: #fff0cc; color: #77521a; }
.inactive { background: #e9ecee; color: #4b5660; }
.cancelled { background: #f6ded9; color: #8e3327; }
.attendance-time { display: block; color: var(--muted); margin-top: 4px; }
.attendance-summary > .button { margin-top: 8px; }
img { display: block; max-width: 100%; height: auto; max-height: 70dvh; object-fit: contain; margin: auto; }
.attendance-thumbnail { width: 220px; height: 160px; object-fit: contain; margin: 8px 0 0; background: #f2f1eb; border-radius: 8px; }
.modal { z-index: 102; }
</style>
