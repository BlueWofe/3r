<script setup lang="ts">
import type { Session } from "~/types";
const props = defineProps<{
  session: Session;
  openOnMount?: boolean;
  admin?: boolean;
  detailFirst?: boolean;
}>();
const emit = defineEmits(["updated", "close"]);
const { user, can } = useAuth();
const detailEditing = ref(false);
const { prisons, load: loadPrisons } = usePrisons();
async function enterDetailEditing() {
  detailEditing.value = true;
  await Promise.all([loadTeachers(), canEditSession.value ? loadPrisons() : Promise.resolve()]);
}
function backToDetail() { detailEditing.value = false; editor.value = false; error.value = ""; }
const selfOpen = ref(false), selfReason = ref(""), selfPhoto = ref<File | null>(null), selfError = ref(""), pending = ref(false);
const open = ref(!!props.openOnMount),
  editor = ref(false),
  reason = ref(""),
  teacherId = ref<number | undefined>(),
  assignmentId = ref<number | undefined>(),
  photo = ref<File | null>(null),
  present = ref(true),
  resolution = ref(false),
  override = ref(false),
  teachers = ref<any[]>([]);
const { error, run } = useApiError();
const mine = computed(() => {
  const own = props.session.assignments.filter(
    (a) => a.teacher_id === user.value?.id,
  );
  return (
    own.find((a) => a.status === "assigned") ||
    own.find((a) => a.status === "leave") ||
    own[0]
  );
});
const admin = computed(() => can("schedule.update.all"));
const attendanceAdmin = computed(() => can("attendance.update.all"));
const editable = computed(() => admin.value || can("schedule.update.own"));
const active = computed(() => props.session.status === "scheduled");
const mutable = computed(
  () =>
    active.value &&
    new Date(
      `${props.session.service_date}T${props.session.end_time}:00+08:00`,
    ).getTime() > Date.now() &&
    !props.session.assignments.some((a) => a.attendance),
);
const ownMutable = computed(
  () =>
    mutable.value &&
    can("schedule.update.own") &&
    ["assigned", "leave"].includes(mine.value?.status),
);
const canEditSession = computed(
  () =>
    admin.value ||
    (ownMutable.value &&
      mine.value?.status === "assigned" &&
      (props.session.original_teacher_count ??
        props.session.assignments.filter((a) => a.status !== "replaced")
          .length) === 1),
);
const today = taipeiDate();
const lateCheckIn = computed(() => props.session.service_date < today);
const canCheckIn = computed(
  () =>
    active.value &&
    mine.value?.status === "assigned" &&
    !mine.value.attendance &&
    props.session.service_date <= today &&
    can("attendance.create.own"),
);
function openSelfAttendance() {
  selfReason.value = "";
  selfPhoto.value = null;
  selfError.value = "";
  selfOpen.value = true;
}
function selectSelfPhoto(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0] || null;
  selfError.value = "";
  selfPhoto.value = null;
  if (file && (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024)) {
    selfError.value = "照片限 JPG、PNG 或 WebP，大小不可超過 5 MB。";
    input.value = "";
    return;
  }
  selfPhoto.value = file;
}
async function submitSelfAttendance() {
  if (pending.value) return;
  selfError.value = "";
  const own = mine.value;
  if (!canCheckIn.value || !own) { selfError.value = "目前無法簽到，請重新整理行程。"; return; }
  if (lateCheckIn.value && !selfReason.value.trim()) { selfError.value = "請填寫補簽原因。"; return; }
  if (selfReason.value.length > 1000) { selfError.value = "備註／原因不可超過 1000 字。"; return; }
  const data = new FormData();
  data.append("mode", "self");
  if (selfReason.value.trim()) data.append("reason", selfReason.value.trim());
  if (selfPhoto.value) data.append("photo", selfPhoto.value);
  pending.value = true;
  try {
    await api(`/assignments/${own.id}/attendance`, { method: "POST", body: data });
    selfOpen.value = false;
    emit("updated");
  } catch (e: any) { selfError.value = e.message || "簽到失敗，請稍後再試。"; }
  finally { pending.value = false; }
}
async function loadTeachers() {
  if (editable.value)
    try {
      teachers.value = (await api<any>("/teachers")).data || [];
    } catch (e: any) {
      error.value = e.message;
    }
}
async function doAssignment(
  kind: "leave" | "withdraw-leave" | "invite" | "replace" | "attendance",
  assignment?: any,
) {
  if (pending.value) return;
  pending.value = true;
  try { await assignmentAction(kind, assignment); }
  catch { /* useApiError displays the server error in the dialog. */ }
  finally { pending.value = false; }
}
async function assignmentAction(
  kind: "leave" | "withdraw-leave" | "invite" | "replace" | "attendance",
  assignment?: any,
) {
  const a =
    assignment ||
    (admin.value || attendanceAdmin.value
      ? props.session.assignments.find(
          (item: any) => item.id === assignmentId.value,
        )
      : mine.value);
  if (!a) {
    error.value = "請先選擇目前指派的同工。";
    return;
  }
  if (kind !== "attendance" && !reason.value.trim()) {
    error.value = "請填寫異動原因。";
    return;
  }
  const body: any = { version: props.session.version, reason: reason.value };
  if (kind === "invite" || kind === "replace")
    body.teacher_id = teacherId.value;
  if (kind === "replace") {
    body.override_conflict = override.value;
    if (resolution.value) body.attendance_resolution = "void";
  }
  if (kind === "attendance") {
    if (attendanceAdmin.value && !reason.value.trim()) {
      error.value = "管理補登請填寫異動原因。";
      return;
    }
    const data = new FormData();
    data.append("mode", "admin");
    if (reason.value.trim()) data.append("reason", reason.value);
    if (attendanceAdmin.value)
      data.append("present", present.value ? "1" : "0");
    if (photo.value) data.append("photo", photo.value);
    await run(() =>
      api(`/assignments/${a.id}/attendance`, { method: "POST", body: data }),
    );
  } else
    await run(() =>
      api(`/assignments/${a.id}/${kind}`, { method: "POST", body }),
    );
  emit("updated");
  open.value = false;
}
async function setStatus(status: "cancelled" | "scheduled") {
  if (pending.value) return;
  pending.value = true;
  try { await updateStatus(status); }
  catch { /* useApiError displays the server error in the dialog. */ }
  finally { pending.value = false; }
}
async function updateStatus(status: "cancelled" | "scheduled") {
  if (!reason.value.trim()) {
    error.value = "請填寫異動原因。";
    return;
  }
  await run(() =>
    api(`/sessions/${props.session.id}`, {
      method: "PUT",
      body: {
        version: props.session.version,
        status,
        reason: reason.value,
        attendance_resolution: resolution.value ? "void" : undefined,
        override_conflict: override.value,
      },
    }),
  );
  emit("updated");
  open.value = false;
}
async function assignTeacher() {
  if (pending.value) return;
  pending.value = true;
  try { await submitTeacher(); }
  catch { /* useApiError displays the server error in the dialog. */ }
  finally { pending.value = false; }
}
async function submitTeacher() {
  if (!teacherId.value || !reason.value.trim()) {
    error.value = "請選擇老師並填寫指派原因。";
    return;
  }
  await run(() =>
    api(`/sessions/${props.session.id}/assign`, {
      method: "POST",
      body: {
        version: props.session.version,
        teacher_id: teacherId.value,
        reason: reason.value,
        override_conflict: override.value,
      },
    }),
  );
  emit("updated");
  open.value = false;
}
watch(
  open,
  async (value) => {
    if (value && !props.detailFirst) await loadTeachers();
  },
  { immediate: true },
);
</script>
<template>
  <div v-if="!openOnMount" class="actions session-action-buttons">
    <button v-if="canCheckIn" class="button gold" :data-testid="lateCheckIn ? 'self-late-check-in' : 'self-check-in'" :disabled="pending" @click="openSelfAttendance">{{ lateCheckIn ? "補簽" : "簽到" }}</button>
  <button class="button ghost" :disabled="pending" @click="open = true">
    {{ admin ? "管理場次" : "處理服務" }}
  </button>
  </div>
  <div v-if="open && !(detailFirst && selfOpen)" class="modal" @keydown.esc="!pending && (open = false, emit('close'))">
    <div class="dialog" role="dialog" aria-modal="true" :aria-label="session.title" :data-testid="detailFirst ? 'calendar-session-detail' : undefined">
      <div class="workhead">
        <div>
          <h2>{{ session.title }}</h2>
          <p v-if="session.class_name" class="muted">班級：{{ session.class_name }}</p>
          <p class="muted">
            {{ session.service_date }} {{ session.start_time }}–{{
              session.end_time
            }}
            · {{ session.location }}
          </p>
        </div>
        <button
          class="button ghost"
          :disabled="pending"
          @click="
            open = false;
            emit('close');
          "
        >
          關閉
        </button>
      </div>
      <h3>授課老師與簽到</h3>
      <div class="attendance-list"><div v-for="a in session.assignments" :key="a.id" :class="{ 'my-assignment': a.teacher_id === user?.id && a.status !== 'replaced' }">
        <span v-if="a.teacher_id === user?.id && a.status !== 'replaced'" class="status scheduled">我的課程{{ a.status === 'leave' ? '／已請假' : '' }}</span>
        <AttendanceSummary :assignment="a" :session-status="session.status" :inline-photo="detailFirst" show-missing />
      </div></div>
      <p v-if="!session.assignments.length" class="vacant-badge"><NavIcon name="person" />缺額：尚未指派老師。</p>
      <template v-if="detailFirst && !detailEditing">
        <dl class="session-details">
          <div><dt>日期與時段</dt><dd>{{ session.service_date }} {{ session.start_time }}–{{ session.end_time }}</dd></div>
          <div><dt>監所／單位</dt><dd>{{ session.prison || '未提供' }}</dd></div>
          <div v-if="session.prison_address"><dt>監所地址</dt><dd>{{ session.prison_address }}</dd></div>
          <div><dt>上課位置</dt><dd>{{ session.location || '未提供' }}</dd></div>
          <div><dt>班級</dt><dd>{{ session.class_name || '未提供' }}</dd></div>
          <div><dt>參與人數</dt><dd>{{ session.participant_count }} 人</dd></div>
          <div><dt>場次狀態</dt><dd>{{ active ? '已排定' : '停課' }}</dd></div>
        </dl>
        <div class="actions">
          <button v-if="canCheckIn" class="button gold" :data-testid="lateCheckIn ? 'self-late-check-in' : 'self-check-in'" :disabled="pending" @click="openSelfAttendance">{{ lateCheckIn ? '補簽' : '簽到' }}</button>
          <button v-if="admin || attendanceAdmin" class="button ghost" data-testid="session-detail-edit" @click="enterDetailEditing">編輯</button>
          <button v-else-if="ownMutable" class="button ghost" data-testid="session-detail-service-actions" @click="enterDetailEditing">請假／換課</button>
        </div>
      </template>
      <template v-else>
      <button v-if="detailFirst" class="button ghost" data-testid="session-detail-back" :disabled="pending" @click="backToDetail">返回詳細資料</button>
      <SessionEditor v-if="detailFirst && detailEditing && canEditSession" :session="session" :teachers="teachers" :prisons="prisons" embedded @saved="emit('updated'); backToDetail()" />
      <fieldset :disabled="pending" class="session-fields">
      <button v-if="canCheckIn" class="button gold" :data-testid="lateCheckIn ? 'self-late-check-in' : 'self-check-in'" :disabled="pending" @click="openSelfAttendance">{{ lateCheckIn ? "補簽" : "簽到" }}</button>
      <label class="field"
        >異動原因／備註<textarea
          v-model="reason"
          placeholder="請說明異動原因"
        ></textarea></label
      ><label
        v-if="(admin || attendanceAdmin) && session.assignments.length"
        class="field"
        >目前指派<select v-model="assignmentId">
          <option :value="undefined">請選擇目前同工</option>
          <option v-for="a in session.assignments" :key="a.id" :value="a.id">
            {{ a.teacher?.name || "缺額" }} · {{ a.status }}
          </option>
        </select></label
      ><label v-if="admin || ownMutable" class="field"
        >選擇同工／指派對象<select v-model="teacherId">
          <option :value="undefined">請選擇</option>
          <option v-for="t in teachers" :key="t.id" :value="t.id">
            {{ t.name }}
          </option>
        </select></label
      ><label v-if="admin" class="field"
        ><input v-model="override" type="checkbox" /> 確認覆蓋時間衝突</label
      ><label v-if="admin" class="field"
        ><input v-model="resolution" type="checkbox" />
        將既有簽到記錄作廢</label
      >
      <div v-if="active" class="actions">
        <button
          v-if="ownMutable && mine?.status === 'assigned'"
          class="button"
          @click="doAssignment('leave', mine)"
        >
          請假</button
        ><button
          v-if="ownMutable && mine?.status === 'leave'"
          class="button ghost"
          @click="doAssignment('withdraw-leave', mine)"
        >
          撤回請假</button
        ><button
          v-if="ownMutable"
          class="button ghost"
          @click="doAssignment('invite', mine)"
        >
          邀請代課</button
        ><button
          v-if="canEditSession && !detailFirst"
          class="button ghost"
          @click="editor = true"
        >
          完整編輯場次</button
        ><button
          v-if="admin && !session.assignments.length"
          class="button"
          @click="assignTeacher"
        >
          指派老師</button
        ><button
          v-if="admin"
          class="button ghost"
          @click="doAssignment('replace')"
        >
          重新指派</button
        ><label
          v-if="attendanceAdmin && session.assignments.length"
          class="field"
          style="display: flex; gap: 7px; align-items: center"
          ><input v-model="present" type="checkbox" /> 補登為出席</label
        ><button
          v-if="attendanceAdmin && session.assignments.length"
          class="button gold"
          @click="doAssignment('attendance')"
        >
          管理補登／更正</button
        ><button
          v-if="canEditSession"
          class="button danger"
          @click="setStatus('cancelled')"
        >
          停課
        </button>
      </div>
      <div v-else class="actions">
        <button v-if="admin" class="button" @click="setStatus('scheduled')">
          恢復場次
        </button>
      </div>
      </fieldset>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      </template>
    </div>
  </div>
  <div v-if="selfOpen" class="modal self-attendance-modal" @keydown.esc="!pending && (selfOpen = false)">
    <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="self-attendance-title" data-testid="self-attendance-dialog">
      <div class="workhead"><h2 id="self-attendance-title">{{ lateCheckIn ? "補簽" : "簽到" }}</h2><button class="button ghost" :disabled="pending" @click="selfOpen = false">關閉</button></div>
      <p>{{ session.title }} · {{ session.service_date }}<br />{{ mine?.teacher?.name || user?.name }}</p>
      <p class="muted">{{ lateCheckIn ? "補簽會保留原服務日期，並記錄現在的簽到時間。" : "簽到時間以送出時的台北時間記錄。" }}</p>
      <form @submit.prevent="submitSelfAttendance">
        <label class="field">{{ lateCheckIn ? "補簽原因（必填）" : "簽到備註（選填）" }}<textarea v-model="selfReason" data-testid="self-attendance-reason" :required="lateCheckIn" maxlength="1000" :disabled="pending"></textarea></label>
        <label class="field">簽到照片（選填，JPG／PNG／WebP，最多 5 MB）<input data-testid="self-attendance-photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" :disabled="pending" @change="selectSelfPhoto" /></label>
        <p v-if="selfError" class="error" role="alert" data-testid="self-attendance-error">{{ selfError }}</p>
        <button class="button gold" data-testid="self-attendance-submit" type="submit" :disabled="pending">{{ pending ? "送出中…" : lateCheckIn ? "送出補簽" : "確認簽到" }}</button>
      </form>
    </section>
  </div>
  <SessionEditor
    v-if="editor"
    :session="session"
    :teachers="teachers"
    @close="editor = false"
    @saved="
      emit('updated');
      editor = false;
      open = false;
    "
  />
</template>
<style scoped>
.session-action-buttons { flex-wrap: wrap; }
.session-fields { border: 0; padding: 0; margin: 0; min-width: 0; }
.attendance-list { display: grid; gap: 12px; margin-bottom: 16px; }
.attendance-list > div { padding: 12px; border: 1px solid var(--line); border-radius: 8px; }
.self-attendance-modal { z-index: 101; }
.self-attendance-modal input { max-width: 100%; }
.session-details { display: grid; gap: 10px; }
.session-details > div { display: grid; grid-template-columns: minmax(80px, 110px) minmax(0, 1fr); gap: 12px; }
.session-details dt { color: var(--muted); }
.session-details dd { margin: 0; overflow-wrap: anywhere; }
.my-assignment { border-left: 4px solid var(--pine); padding: 10px; background: #eaf1eb; border-radius: 8px; }
.vacant-badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #e9ecee; color: #4b5660; border-radius: 8px; font-size: 16px; font-weight: 700; }
.dialog { overflow-wrap: anywhere; }
</style>
