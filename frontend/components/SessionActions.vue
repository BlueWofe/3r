<script setup lang="ts">
import type { Session } from "~/types";
const props = defineProps<{
  session: Session;
  openOnMount?: boolean;
  admin?: boolean;
}>();
const emit = defineEmits(["updated", "close"]);
const { user, can, refresh } = useAuth();
const open = ref(!!props.openOnMount),
  editor = ref(false),
  reason = ref(""),
  teacherId = ref<number | undefined>(),
  present = ref(true),
  resolution = ref(false),
  override = ref(false),
  teachers = ref<any[]>([]);
const { error, run } = useApiError();
const mine = computed(() =>
  props.session.assignments?.find((a: any) => a.teacher_id === user.value?.id),
);
const admin = computed(() => props.admin || can("schedule.update.all"));
const editable = computed(() => admin.value || can("schedule.update.own"));
const active = computed(() => props.session.status === "scheduled");
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
  const a = assignment || mine.value;
  if (!a) return;
  if (!reason.value.trim()) {
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
    body.present = present.value;
    await run(() =>
      api(`/assignments/${a.id}/attendance`, { method: "POST", body }),
    );
  } else
    await run(() =>
      api(`/assignments/${a.id}/${kind}`, { method: "POST", body }),
    );
  emit("updated");
  open.value = false;
}
async function setStatus(status: "cancelled" | "scheduled") {
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
onMounted(async () => {
  await refresh();
  await loadTeachers();
});
</script>
<template>
  <button class="button ghost" @click="open = true">
    {{ admin ? "管理場次" : "處理服務" }}
  </button>
  <div v-if="open" class="modal">
    <div class="dialog">
      <div class="workhead">
        <div>
          <h2>{{ session.title }}</h2>
          <p class="muted">
            {{ session.service_date }} {{ session.start_time }}–{{
              session.end_time
            }}
            · {{ session.location }}
          </p>
        </div>
        <button
          class="button ghost"
          @click="
            open = false;
            emit('close');
          "
        >
          關閉
        </button>
      </div>
      <label class="field"
        >異動原因／備註<textarea
          v-model="reason"
          required
          placeholder="請說明異動原因"
        ></textarea></label
      ><label v-if="admin" class="field"
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
          v-if="mine?.status === 'assigned'"
          class="button"
          @click="doAssignment('leave')"
        >
          請假</button
        ><button
          v-if="mine?.status === 'leave'"
          class="button ghost"
          @click="doAssignment('withdraw-leave')"
        >
          撤回請假</button
        ><button
          v-if="mine?.status === 'leave' && editable"
          class="button ghost"
          @click="doAssignment('invite')"
        >
          邀請代課</button
        ><button v-if="editable" class="button ghost" @click="editor = true">
          完整編輯場次</button
        ><button
          v-if="admin"
          class="button ghost"
          @click="
            doAssignment(
              'replace',
              session.assignments.find((a) => a.teacher_id === teacherId) ||
                session.assignments[0],
            )
          "
        >
          重新指派</button
        ><label
          v-if="admin"
          class="field"
          style="display: flex; gap: 7px; align-items: center"
          ><input v-model="present" type="checkbox" /> 補登為出席</label
        ><button
          v-if="admin"
          class="button gold"
          @click="
            doAssignment(
              'attendance',
              session.assignments.find((a) => a.teacher_id === teacherId) ||
                session.assignments[0],
            )
          "
        >
          補登簽到</button
        ><button
          v-if="editable"
          class="button danger"
          @click="setStatus('cancelled')"
        >
          停課
        </button>
      </div>
      <div v-else class="actions">
        <button v-if="editable" class="button" @click="setStatus('scheduled')">
          恢復場次
        </button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </div>
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
