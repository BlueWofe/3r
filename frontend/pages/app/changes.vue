<script setup lang="ts">
definePageMeta({ layout: "app" });
const rows = ref<any[]>([]);
const { error, run } = useApiError();
async function load() {
  rows.value = (await api<any>("/changes")).data || [];
}
async function ack(r: any) {
  await run(() => api(`/changes/${r.id}/acknowledge`, { method: "POST" }));
  await load();
}
onMounted(load);
</script>
<template>
  <div class="workhead">
    <div>
      <p class="eyebrow">UPDATES</p>
      <h1>異動通知</h1>
    </div>
  </div>
  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>時間</th>
          <th>異動內容</th>
          <th>變更前 → 後</th>
          <th>狀態</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id">
          <td data-label="時間">{{ r.created_at || r.service_date }}</td>
          <td data-label="異動內容">{{ r.message || r.reason || r.type }}</td>
          <td data-label="變更前 → 後">
            {{ r.before || r.before_value || "—" }} →
            {{ r.after || r.after_value || "—" }}
          </td>
          <td data-label="狀態">
            {{ r.acknowledged_at ? "已確認" : "待確認" }}
          </td>
          <td data-label="操作">
            <button v-if="!r.acknowledged_at" class="button" @click="ack(r)">
              確認已閱
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-if="error" class="error">{{ error }}</p>
</template>
