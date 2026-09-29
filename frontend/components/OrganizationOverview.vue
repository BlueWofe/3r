<script setup lang="ts">
const props = defineProps<{ metadata?: Record<string, any> | null }>();
const text = (value: unknown) =>
  typeof value === "string" ? value.trim() : "";
const has = (key: string) =>
  Object.prototype.hasOwnProperty.call(props.metadata || {}, key);
const isDemo = computed(() => props.metadata?.demo === true);
const levels = computed(() => {
  const value = props.metadata?.organization_levels;
  if (Array.isArray(value)) return value.map(text).filter(Boolean);
  return isDemo.value && !has("organization_levels")
    ? ["會員大會（示範）", "理監事（示範）", "服務團隊（示範）"]
    : [];
});
const departments = computed(() => {
  const value = props.metadata?.departments;
  if (Array.isArray(value))
    return value
      .filter((item) => item && text(item.name))
      .map((item) => ({
        name: text(item.name),
        description: text(item.description),
      }));
  return isDemo.value && !has("departments")
    ? [
        { name: "監所關懷", description: "走進高牆，以傾聽與信仰陪伴生命。" },
        { name: "更生陪伴", description: "支持重新出發，連結家庭與社區。" },
        { name: "志工培力", description: "彼此裝備，讓服務持續而有力量。" },
        { name: "行政會務", description: "協調事工與資源，支持第一線服務。" },
      ]
    : [];
});
const team = computed(() => {
  const value = props.metadata?.team;
  if (Array.isArray(value))
    return value
      .filter((item) => item && text(item.name))
      .map((item) => ({
        name: text(item.name),
        role: text(item.role),
        bio: text(item.bio),
      }));
  return isDemo.value && !has("team")
    ? [
        {
          name: "關懷同工（示範）",
          role: "監所關懷",
          bio: "在每一次相遇裡，陪伴生命尋回盼望。",
        },
        {
          name: "陪伴同工（示範）",
          role: "更生陪伴",
          bio: "以實際支持，陪伴重新出發的每一步。",
        },
        {
          name: "會務同工（示範）",
          role: "事工協調",
          bio: "連結志工與資源，成為服務團隊的後盾。",
        },
      ]
    : [];
});
</script>
<template>
  <div class="organization-overview">
    <p v-if="isDemo" class="organization-demo">
      示範架構與同工介紹版型・正式資料待協會確認
    </p>
    <div
      v-if="levels.length || departments.length"
      class="organization-chart"
      aria-label="協會組織架構"
    >
      <ol v-if="levels.length" class="organization-tree">
        <li v-for="(level, index) in levels" :key="index">
          <span>{{ level }}</span>
        </li>
      </ol>
      <div v-if="departments.length" class="department-grid">
        <article
          v-for="(department, index) in departments"
          :key="index"
          class="department-card"
        >
          <span class="department-number" aria-hidden="true">{{
            String(index + 1).padStart(2, "0")
          }}</span>
          <h3>{{ department.name }}</h3>
          <p>{{ department.description }}</p>
        </article>
      </div>
    </div>
    <p v-else class="muted">組織架構待協會補充。</p>
    <div id="team" class="team-section">
      <p class="eyebrow">PEOPLE WHO CARE</p>
      <h3 class="team-heading">一起同行的同工</h3>
      <p class="muted">每一份陪伴，都來自願意付出時間與關懷的人。</p>
      <div v-if="team.length" class="team-grid">
        <article v-for="(member, index) in team" :key="index" class="team-card">
          <span class="team-avatar" aria-hidden="true"
            ><NavIcon name="person"
          /></span>
          <p class="team-role">{{ member.role }}</p>
          <h4>{{ member.name }}</h4>
          <p class="muted">{{ member.bio }}</p>
        </article>
      </div>
      <p v-else class="muted">同工介紹待協會補充。</p>
    </div>
  </div>
</template>
<style scoped>
.organization-demo {
  font-size: 13px;
  color: var(--muted);
  border-left: 3px solid var(--gold);
  padding: 8px 14px;
  margin: 28px 0;
}
.organization-chart {
  background: #f4f6f2;
  padding: clamp(20px, 4vw, 48px);
  border-radius: 24px;
  margin-top: 32px;
}
.organization-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  text-align: center;
}
.organization-tree li + li::before {
  content: "";
  display: block;
  width: 1px;
  height: 24px;
  background: #a7b7a7;
  margin: auto;
}
.organization-tree span {
  display: inline-block;
  max-width: 100%;
  min-width: min(220px, 100%);
  padding: 14px 24px;
  background: var(--pine);
  color: white;
  border-radius: 12px;
  overflow-wrap: anywhere;
}
.organization-tree li:first-child span {
  background: #dce5db;
  color: var(--pine);
}
.department-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr));
  gap: 16px;
  margin-top: 32px;
}
.department-card {
  background: #fffdf8;
  border-radius: 16px;
  padding: 24px 20px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.department-number {
  font-size: 13px;
  letter-spacing: 0.15em;
  color: #8d6a2d;
}
.department-card h3 {
  font-size: 19px;
  margin: 12px 0 8px;
}
.department-card p {
  font-size: 14px;
  margin: 0;
  color: var(--muted);
}
.team-section {
  padding-top: 64px;
  scroll-margin-top: 140px;
}
.team-heading {
  font-size: clamp(25px, 3vw, 34px);
  margin: 12px 0;
}
.team-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 24px;
  margin-top: 32px;
}
.team-card {
  padding: 32px 24px;
  background: #fffdf8;
  border: 1px solid var(--line);
  border-radius: 20px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.team-avatar {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: #e6ece4;
  color: var(--pine);
  margin-bottom: 24px;
}
.team-avatar :deep(svg) {
  width: 32px;
  height: 32px;
}
.team-role {
  font-size: 13px;
  color: #8d6a2d;
  margin: 0 0 8px;
}
.team-card h4 {
  font-size: 22px;
  margin: 0 0 12px;
}
.team-card .muted {
  font-size: 15px;
  margin: 0;
}
@media (max-width: 760px) {
  .department-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .department-card {
    padding: 18px 14px;
  }
  .department-card h3 {
    font-size: 17px;
  }
  .team-section {
    padding-top: 40px;
  }
}
@media (max-width: 360px) {
  .department-grid {
    grid-template-columns: 1fr;
  }
}
</style>
