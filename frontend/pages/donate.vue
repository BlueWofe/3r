<script setup lang="ts">
const amount = useState<number>("giving-amount", () => 500);
const purpose = useState<string>("giving-purpose", () => "一般奉獻");
const donation = ref<any>(null),
  review = ref(false),
  busy = ref(false);
const ready = ref(false);
onMounted(() => {
  ready.value = true;
});
const { loggedIn } = useAuth();
const { error, run } = useApiError();
const resultLabels: Record<string, string> = {
  success: "成功",
  failed: "失敗",
  cancelled: "已取消",
};
const currentStep = computed(() => (donation.value ? 3 : review.value ? 2 : 1));
const terminal = computed(
  () => donation.value && donation.value.status !== "pending",
);
const summary = computed(() => ({
  amount: donation.value?.amount ?? amount.value,
  purpose: donation.value?.purpose ?? purpose.value,
}));
async function create() {
  if (busy.value || !loggedIn.value || donation.value) return;
  busy.value = true;
  try {
    donation.value = await run(() =>
      api<any>("/donations", {
        method: "POST",
        body: { amount: amount.value, purpose: purpose.value },
      }),
    );
  } catch {
    /* Keep the API error visible and allow retry. */
  } finally {
    busy.value = false;
  }
}
async function simulate(value: string) {
  if (busy.value || !donation.value || terminal.value) return;
  busy.value = true;
  try {
    donation.value = await run(() =>
      api<any>(`/donations/${donation.value.id}/simulate`, {
        method: "POST",
        body: { result: value },
      }),
    );
  } catch {
    /* Do not display a success state for a failed request. */
  } finally {
    busy.value = false;
  }
}
function restart() {
  donation.value = null;
  review.value = false;
  error.value = "";
}
</script>
<template>
  <div class="pagehead">
    <div class="container">
      <div class="eyebrow">GIVING WITH PURPOSE</div>
      <h1>把支持，化成陪伴</h1>
      <p class="muted">每一份心意，都是一起走下去的力量。</p>
    </div>
  </div>
  <section class="section">
    <div class="container giving-layout">
      <aside class="giving-purpose">
        <p class="eyebrow">YOUR KINDNESS MATTERS</p>
        <h2>讓盼望，<br />走進更多生命。</h2>
        <p class="muted giving-lead">
          協助監獄更生人，也發展監獄事工。我們邀請您以一份支持，參與長期的關懷與同行。
        </p>
        <ul class="giving-impact">
          <li>
            <NavIcon name="book" />
            <div>
              <h3>監所關懷</h3>
              <p>支持入監服務、生命教育與信仰陪伴。</p>
            </div>
          </li>
          <li>
            <NavIcon name="people" />
            <div>
              <h3>更生陪伴</h3>
              <p>陪伴重新出發，連結家庭與社區的支持。</p>
            </div>
          </li>
          <li>
            <NavIcon name="home" />
            <div>
              <h3>家庭支持</h3>
              <p>以傾聽與關懷，支持關係的修復與重建。</p>
            </div>
          </li>
        </ul>
        <FullVerse />
      </aside>
      <div class="giving-panel">
        <ol class="giving-steps" aria-label="奉獻操作步驟">
          <li
            v-for="(label, index) in ['選擇支持', '確認內容', '模擬結果']"
            :key="label"
            :aria-current="currentStep === index + 1 ? 'step' : undefined"
          >
            <span>{{ index + 1 }}</span
            >{{ label }}
          </li>
        </ol>
        <p class="giving-mode">
          <NavIcon name="shield" /><span
            >目前為模擬奉獻，不會扣款或開立正式收據。</span
          >
        </p>
        <form
          v-if="!donation && !review"
          class="giving-form"
          @submit.prevent="review = true"
        >
          <fieldset :disabled="!ready || busy">
            <legend>1. 奉獻方式</legend>
            <div class="giving-methods">
              <span class="giving-method selected"
                ><NavIcon name="heart" /><strong>單筆奉獻</strong
                ><small>模擬體驗</small></span
              ><span class="giving-method unavailable"
                ><NavIcon name="calendar" /><strong>每月定額</strong
                ><small>待開通</small></span
              >
            </div>
          </fieldset>
          <label class="field"
            >2. 支持用途<select v-model="purpose" :disabled="!ready || busy">
              <option>一般奉獻</option>
              <option>監所關懷</option>
              <option>更生陪伴</option>
              <option>家庭支持</option>
            </select></label
          >
          <fieldset :disabled="!ready || busy">
            <legend>3. 選擇支持金額</legend>
            <div class="giving-amounts">
              <button
                v-for="preset in [500, 1000, 2000, 5000]"
                :key="preset"
                type="button"
                :aria-pressed="amount === preset"
                :class="['amount-preset', { selected: amount === preset }]"
                @click="amount = preset"
              >
                {{ preset.toLocaleString("zh-TW") }} 元
              </button>
            </div>
            <label class="field"
              >支持金額（元）<input
                v-model.number="amount"
                type="number"
                min="1"
                max="1000000"
                step="1"
                required
                inputmode="numeric"
            /></label>
          </fieldset>
          <p v-if="!loggedIn" class="giving-signin">
            登入後可建立並查看自己的模擬奉獻紀錄。<NuxtLink
              to="/login?returnTo=/donate"
              >登入／註冊後繼續</NuxtLink
            >
          </p>
          <button
            class="button gold giving-primary"
            type="submit"
            :disabled="!ready || busy"
          >
            確認奉獻內容 <span aria-hidden="true">→</span>
          </button>
        </form>
        <section
          v-else-if="!donation"
          class="giving-confirm"
          aria-labelledby="giving-confirm-title"
        >
          <h2 id="giving-confirm-title">確認奉獻內容</h2>
          <dl class="giving-summary">
            <div>
              <dt>奉獻方式</dt>
              <dd>單筆模擬</dd>
            </div>
            <div>
              <dt>支持用途</dt>
              <dd>{{ summary.purpose }}</dd>
            </div>
            <div>
              <dt>支持金額</dt>
              <dd class="giving-total">
                NT$ {{ Number(summary.amount).toLocaleString("zh-TW") }}
              </dd>
            </div>
          </dl>
          <p class="muted">確認後建立一筆模擬紀錄，再選擇模擬結果。</p>
          <button
            v-if="loggedIn"
            class="button gold giving-primary"
            :disabled="busy"
            @click="create"
          >
            {{ busy ? "處理中…" : "確認建立模擬捐款" }}</button
          ><NuxtLink
            v-else
            class="button gold giving-primary"
            to="/login?returnTo=/donate"
            >登入／註冊後繼續</NuxtLink
          ><button
            class="button ghost"
            :disabled="busy"
            @click="review = false"
          >
            返回修改
          </button>
        </section>
        <section
          v-else
          class="giving-confirm"
          aria-labelledby="giving-result-title"
        >
          <h2 id="giving-result-title">
            {{ terminal ? "奉獻模擬結果" : "確認模擬結果" }}
          </h2>
          <dl class="giving-summary">
            <div>
              <dt>支持用途</dt>
              <dd>{{ summary.purpose }}</dd>
            </div>
            <div>
              <dt>支持金額</dt>
              <dd class="giving-total">
                NT$ {{ Number(summary.amount).toLocaleString("zh-TW") }}
              </dd>
            </div>
          </dl>
          <template v-if="!terminal"
            ><p class="muted">選擇一種結果，體驗奉獻流程。</p>
            <div class="actions giving-results">
              <button
                class="button"
                :disabled="busy"
                @click="simulate('success')"
              >
                模擬成功</button
              ><button
                class="button ghost"
                :disabled="busy"
                @click="simulate('failed')"
              >
                模擬失敗</button
              ><button
                class="button ghost"
                :disabled="busy"
                @click="simulate('cancelled')"
              >
                取消
              </button>
            </div>
            <p v-if="busy" role="status">處理中…</p></template
          ><template v-else
            ><p class="notice giving-result" role="status">
              模擬交易結果：{{
                resultLabels[donation.status] || "待確認"
              }}。沒有產生真實付款。
            </p>
            <p v-if="donation.status === 'success'" class="muted">
              謝謝您參與這份陪伴。這筆紀錄已保存至您的個人工作台。
            </p>
            <NuxtLink class="button" to="/app/profile"
              >查看我的奉獻紀錄</NuxtLink
            ><button class="button ghost" @click="restart">
              再體驗一筆
            </button></template
          >
        </section>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <div class="giving-help">
          <h3>其他支持方式</h3>
          <p>每月奉獻、匯款及郵政劃撥待開通。</p>
          <NuxtLink to="/contact"
            >洽詢協會 <span aria-hidden="true">↗</span></NuxtLink
          >
        </div>
      </div>
    </div>
  </section>
</template>
<style scoped>
.giving-layout {
  display: grid;
  grid-template-columns: 0.85fr 1fr;
  gap: clamp(32px, 6vw, 80px);
  align-items: start;
}
.giving-purpose {
  min-width: 0;
}
.giving-purpose h2 {
  font-size: clamp(30px, 3.6vw, 44px);
  line-height: 1.5;
}
.giving-lead {
  max-width: 440px;
}
.giving-impact {
  list-style: none;
  margin: 32px 0;
  padding: 0;
}
.giving-impact li {
  display: flex;
  gap: 18px;
  padding: 20px 0;
  border-bottom: 1px solid var(--line);
}
.giving-impact :deep(svg) {
  width: 26px;
  height: 26px;
  flex-shrink: 0;
  margin-top: 4px;
  color: #8d6a2d;
}
.giving-impact h3 {
  font-size: 18px;
  margin: 0 0 5px;
}
.giving-impact p {
  font-size: 14px;
  color: var(--muted);
  margin: 0;
}
.giving-purpose :deep(.full-verse) {
  border-radius: 18px;
  padding: 24px 20px;
  text-align: left;
  margin: 32px 0 0;
}
.giving-purpose :deep(.full-verse .container) {
  width: 100%;
}
.giving-purpose :deep(.full-verse p) {
  font-size: 19px;
}
.giving-panel {
  border: 1px solid var(--line);
  background: #fffdf8;
  border-radius: 24px;
  padding: clamp(20px, 3vw, 36px);
  min-width: 0;
  box-shadow: 0 12px 40px #14312808;
}
.giving-steps {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  padding: 0 0 24px;
  margin: 0;
  border-bottom: 1px solid var(--line);
  font-size: 13px;
  color: var(--muted);
}
.giving-steps li {
  display: flex;
  align-items: center;
  gap: 8px;
}
.giving-steps span {
  display: grid;
  place-items: center;
  width: 25px;
  height: 25px;
  flex-shrink: 0;
  border-radius: 50%;
  background: #e6ece4;
}
.giving-steps [aria-current] {
  color: var(--pine);
  font-weight: 700;
}
.giving-steps [aria-current] span {
  background: var(--pine);
  color: white;
}
.giving-mode {
  display: flex;
  align-items: start;
  gap: 8px;
  font-size: 13px;
  color: var(--muted);
  background: #f4f2e9;
  padding: 12px;
  border-radius: 10px;
  margin: 24px 0;
}
.giving-mode :deep(svg) {
  margin-top: 2px;
  flex-shrink: 0;
}
.giving-form {
  display: grid;
  gap: 24px;
}
.giving-form fieldset {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
.giving-form legend {
  font-weight: 600;
  margin-bottom: 12px;
  font-size: 15px;
}
.giving-methods {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.giving-method {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 8px;
  align-items: center;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  font-size: 14px;
}
.giving-method small {
  grid-column: 2;
  color: var(--muted);
}
.giving-method.selected {
  background: #eef3e9;
  border-color: #64795c;
}
.giving-method.unavailable {
  color: var(--muted);
  background: #f9f7f2;
}
.giving-amounts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 14px;
}
.amount-preset {
  padding: 10px 3px;
  min-height: 44px;
  background: white;
  border: 1px solid var(--line);
  border-radius: 10px;
  font: inherit;
  font-size: 14px;
  color: var(--pine);
  cursor: pointer;
}
.amount-preset.selected {
  background: #eef3e9;
  border-color: var(--pine);
  box-shadow: inset 0 0 0 1px var(--pine);
}
.giving-signin {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.giving-signin a {
  display: block;
  color: var(--pine);
  text-decoration: underline;
  margin-top: 8px;
}
.giving-primary {
  width: 100%;
  justify-content: center;
  min-height: 48px;
}
.giving-summary {
  margin: 24px 0;
  padding: 0;
}
.giving-summary div {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 0;
  border-bottom: 1px solid var(--line);
}
.giving-summary dt {
  color: var(--muted);
}
.giving-summary dd {
  margin: 0;
  text-align: right;
  overflow-wrap: anywhere;
}
.giving-total {
  font-size: 24px;
  font-weight: 700;
}
.giving-confirm h2 {
  font-size: 26px;
}
.giving-confirm > .button {
  margin-top: 12px;
}
.giving-results {
  gap: 8px;
}
.giving-results .button {
  flex: 1;
  padding: 12px 8px;
}
.giving-help {
  border-top: 1px solid var(--line);
  padding-top: 24px;
  margin-top: 32px;
}
.giving-help h3 {
  font-size: 16px;
  margin: 0;
}
.giving-help p {
  font-size: 13px;
  color: var(--muted);
  margin: 8px 0;
}
.giving-help a {
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  min-height: 44px;
}
button:focus-visible,
a:focus-visible {
  outline: 3px solid var(--gold);
  outline-offset: 3px;
}
@media (max-width: 760px) {
  .giving-panel {
    order: -1;
  }
  .giving-layout {
    grid-template-columns: 1fr;
    gap: 32px;
  }
  .giving-steps li {
    flex-direction: column;
    gap: 6px;
    text-align: center;
  }
  .giving-amounts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .giving-results {
    flex-wrap: wrap;
  }
  .giving-results .button {
    min-width: 100%;
  }
}
</style>
