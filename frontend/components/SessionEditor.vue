<script setup lang="ts">
import type { Session } from "~/types";
const p = defineProps<{
  session?: Session | null;
  teachers?: any[];
  prisons?: any[];
  embedded?: boolean;
}>();
const emit = defineEmits(["close", "saved"]);
const { can } = useAuth();
const manager = computed(() => can("schedule.update.all"));
const form = reactive<any>({
  title: p.session?.title || "",
  class_name: p.session?.class_name || "",
  color: scheduleColor(p.session?.color),
  prison_id: p.session?.prison_id || null,
  location: p.session?.location || "",
  participant_count: p.session?.participant_count || 0,
  service_date:
    p.session?.service_date || taipeiDate(),
  start_time: p.session?.start_time || "09:00",
  end_time: p.session?.end_time || "11:00",
  teacher_ids: p.session?.assignments?.map((a: any) => a.teacher_id) || [],
  repeat_weeks: 1,
  reason: "",
  override_conflict: false,
  attendance_resolution: false,
});
const { error, run } = useApiError();
async function save() {
  const body: any = {
    ...form,
    version: p.session?.version,
    attendance_resolution:
      manager.value && form.attendance_resolution ? "void" : undefined,
  };
  if (p.session && !manager.value) {
    for (const key of [
      "title",
      "class_name",
      "color",
      "prison_id",
      "participant_count",
      "teacher_ids",
      "repeat_weeks",
      "override_conflict",
      "attendance_resolution",
    ])
      delete body[key];
  }
  const r = await run(() =>
    p.session
      ? api(`/sessions/${p.session.id}`, { method: "PUT", body })
      : api("/sessions", { method: "POST", body }),
  );
  emit("saved", r);
  emit("close");
}
</script>
<template>
  <div :class="embedded ? 'embedded-editor' : 'modal'">
    <form :class="embedded ? 'form' : 'dialog form'" :role="embedded ? undefined : 'dialog'" :aria-modal="embedded ? undefined : true" aria-label="課程場次編輯" @submit.prevent="save">
      <div v-if="!embedded" class="workhead">
        <h2>{{ session ? "編輯服務場次" : "新增服務場次" }}</h2>
        <button type="button" class="button ghost" @click="emit('close')">
          關閉
        </button>
      </div>
      <div v-if="session" class="notice editor-guidance"><strong>修改前請先確認</strong><p>填寫異動原因；若涉及老師時間衝突或既有出勤紀錄，請確認後再儲存。</p></div>
      <div class="editor-section-heading"><h3>基本資訊</h3><p class="muted">填寫課程主題、上課地點、人數與服務日期。</p></div>
      <div class="grid responsive-two" style="grid-template-columns: 1fr 1fr">
        <label class="field"
          >課程主題<input
            v-model="form.title"
            :disabled="!!session && !manager"
            required /></label
        ><label class="field"
          >班級名稱<input v-model="form.class_name" :disabled="!!session && !manager" maxlength="200" placeholder="例如：生命更新班" required /></label
        ><label class="field"
          >監所／單位<select
            v-model="form.prison_id"
            :disabled="!!session && !manager"
            required
          >
            <option :value="null">請選擇監所</option>
            <option
              v-for="prison in prisons"
              :key="prison.id"
              :value="prison.id"
              :disabled="
                prison.active === false && prison.id !== form.prison_id
              "
            >
              {{ prison.name }}{{ prison.active === false ? "（已停用）" : "" }}
            </option>
          </select></label
        ><label class="field"
          >上課位置<input v-model="form.location" maxlength="200" placeholder="例如：教化大樓二樓第一教室" required /></label
        ><label class="field"
          >參與人數<input
            v-model.number="form.participant_count"
            :disabled="!!session && !manager"
            type="number"
            min="0" /></label
        ><label class="field"
          >服務日期<input
            v-model="form.service_date"
            type="date"
            required /></label
        ><label v-if="!session" class="field"
          >重複週數（建立時）<input
            v-model.number="form.repeat_weeks"
            type="number"
            min="1"
            max="52" /></label
        ><label class="field"
          >開始時間<input
            v-model="form.start_time"
            type="time"
            required /></label
        ><label class="field"
          >結束時間<input v-model="form.end_time" type="time" required
        /></label>
      </div>
      <ScheduleColorPicker v-model="form.color" label="課程顯示顏色" :disabled="!!session && !manager" />
      <h3>老師與安排</h3>
      <label v-if="!session" class="field"
        >授課／服務同工<select v-model="form.teacher_ids" multiple>
          <option v-for="t in teachers" :key="t.id" :value="t.id">
            {{ t.name }}
          </option>
        </select></label
      ><label v-if="manager" class="field"
        ><input v-model="form.override_conflict" type="checkbox" />
        已確認老師時間衝突，仍要安排</label
      >
      <label
        v-if="manager && session?.assignments.some((a) => a.attendance)"
        class="field"
        ><input v-model="form.attendance_resolution" type="checkbox" />
        作廢既有簽到並保留更正紀錄</label
      >
      <label v-if="session || form.override_conflict" class="field"
        >異動說明<input
          v-model="form.reason"
          required
          placeholder="請說明變更原因"
      /></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="button">儲存場次</button>
    </form>
  </div>
</template>

<style scoped>.editor-guidance p { margin:8px 0 0; } .editor-section-heading h3 { margin:0; } .editor-section-heading p { margin:4px 0 0; font-size:13px; } .embedded-editor { margin:24px 0; padding:24px; border:1px solid var(--r3-border); border-radius:16px; background:var(--r3-paper); } @media(max-width:760px) { .embedded-editor { padding:16px; } }</style>
