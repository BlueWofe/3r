<script setup lang="ts">
const amount = ref(500),
  purpose = ref("一般奉獻"),
  donation = ref<any>(null),
  result = ref("");
const busy = ref(false);
const { error, run } = useApiError();
async function create() {
  if (busy.value) return;
  busy.value = true;
  try {
    const r: any = await run(() =>
      api("/donations", {
        method: "POST",
        body: { amount: amount.value, purpose: purpose.value },
      }),
    );
    donation.value = r;
  } finally {
    busy.value = false;
  }
}
async function simulate(v: string) {
  if (busy.value) return;
  busy.value = true;
  try {
    await run(() =>
      api(`/donations/${donation.value.id}/simulate`, {
        method: "POST",
        body: { result: v },
      }),
    );
    result.value = v;
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">SUPPORT THE MINISTRY</div>
      <h1>支持陪伴事工</h1>
    </div>
  </div>
  <section class="section">
    <div class="container donate-layout">
      <aside class="purpose-panel">
        <p class="eyebrow">WHY WE SERVE</p>
        <h2>把支持，化成陪伴</h2>
        <p class="muted">
          協助監獄更生人，也發展監獄事工。每一份心意，都成為收容人關懷、更生陪伴與家庭支持的力量。
        </p>
        <ul>
          <li>收容人關懷</li>
          <li>更生陪伴</li>
          <li>家庭支持</li>
        </ul>
        <FullVerse />
      </aside>
      <div>
        <div class="notice">
          這是安全的金流模擬頁面。未串接真實付款，不會收款或保存卡號。
        </div>
        <form
          v-if="!donation"
          class="card form"
          style="margin-top: 20px"
          @submit.prevent="create"
        >
          <label class="field"
            >支持金額（元）<input
              v-model.number="amount"
              type="number"
              min="1"
              required
          /></label>
          <div class="actions">
            <button
              v-for="preset in [500, 1000, 2000, 5000]"
              :key="preset"
              type="button"
              :class="['button', amount === preset ? 'gold' : 'ghost']"
              @click="amount = preset"
            >
              {{ preset.toLocaleString() }} 元
            </button>
          </div>
          <label class="field"
            >支持用途<select v-model="purpose">
              <option>一般奉獻</option>
              <option>監所關懷</option>
              <option>家庭支持</option>
            </select></label
          ><button class="button gold" :disabled="busy">
            {{ busy ? "處理中…" : "確認建立模擬捐款" }}
          </button>
          <p class="muted">每月奉獻、匯款及劃撥待開通；如需協助請聯絡協會。</p>
        </form>
        <div v-else class="card">
          <h2>確認模擬結果</h2>
          <p class="muted">金額：{{ amount }} 元　用途：{{ purpose }}</p>
          <div class="actions">
            <button class="button" @click="simulate('success')">模擬成功</button
            ><button class="button ghost" @click="simulate('failed')">
              模擬失敗</button
            ><button class="button ghost" @click="simulate('cancelled')">
              取消
            </button>
          </div>
          <p v-if="result" class="notice">
            模擬交易結果：{{ result }}。沒有產生真實付款。
          </p>
        </div>
        <p v-if="error" class="error">{{ error }}</p>
      </div>
    </div>
  </section>
</template>
