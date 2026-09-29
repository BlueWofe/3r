import { test, expect } from "@playwright/test";
import {
  apiContext,
  demoPassword,
  json,
  login,
  mutate,
  unique,
} from "./helpers";

test("teacher can leave a future calendar session and invite a substitute from mobile and desktop", async ({
  page,
  isMobile,
}) => {
  const admin = await apiContext();
  try {
    await login(admin, "0900000001");
    const { data: users } = await json<{
      data: { id: number; phone: string }[];
    }>(await admin.get("/api/v1/users"));
    const teacher = users.find((u) => u.phone === "0900000002")!;
    const substitute = users.find((u) => u.phone === "0900000004")!;
    const date = new Date();
    date.setDate(date.getDate() + (isMobile ? 3 : 2));
    const serviceDate = new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Taipei",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(date);
    const title = unique(isMobile ? "手機場次" : "桌面場次");
    const session = await json<any>(
      await mutate(admin, "post", "/api/v1/sessions", {
        title,
        prison: "示範場域",
        location: "UI驗收教室",
        participant_count: 0,
        service_date: serviceDate,
        start_time: "23:00",
        end_time: "23:30",
        teacher_ids: [teacher.id],
        override_conflict: true,
        reason: "合成UI驗收場次",
      }),
    );
    await page.goto("/login");
    await page.getByLabel("手機號碼").fill(teacher.phone);
    await page.getByLabel("密碼", { exact: true }).fill(demoPassword!);
    await page
      .locator("form")
      .getByRole("button", { name: "登入", exact: true })
      .click();
    await expect(page).toHaveURL(/\/app(?:\/[^/]+)?$/);
    await page.goto("/app/calendar");
    await page.getByRole("button", { name: "議程", exact: true }).click();
    await page.getByRole("button", { name: new RegExp(title) }).click();
    await page.getByPlaceholder("請說明異動原因").fill("臨時有事，請假測試");
    const leave = page.waitForResponse(
      (r) =>
        r.url().endsWith(`/assignments/${session.assignments[0].id}/leave`) &&
        r.request().method() === "POST",
    );
    await page.getByRole("button", { name: "請假", exact: true }).click();
    expect((await leave).status()).toBe(200);
    await expect(
      page.getByRole("heading", { name: title, exact: true }),
    ).toBeHidden();
    await page.getByRole("button", { name: new RegExp(title) }).click();
    await page.getByPlaceholder("請說明異動原因").fill("邀請同工協助代課");
    await page
      .getByLabel("選擇同工／指派對象")
      .selectOption(String(substitute.id));
    const invite = page.waitForResponse(
      (r) =>
        r.url().endsWith(`/assignments/${session.assignments[0].id}/invite`) &&
        r.request().method() === "POST",
    );
    await page.getByRole("button", { name: "邀請代課", exact: true }).click();
    expect((await invite).status()).toBe(200);
  } finally {
    await admin.dispose();
  }
});

test("member workspace has no scheduling controls and public mobile navigation is reachable", async ({
  page,
  isMobile,
}) => {
  await page.goto("/");
  if (isMobile)
    await page.getByRole("button", { name: "開啟導覽選單" }).click();
  await page.getByRole("link", { name: "會員登入", exact: true }).click();
  await page.getByLabel("手機號碼").fill("0900000003");
  await page.getByLabel("密碼", { exact: true }).fill(demoPassword!);
  await page
    .locator("form")
    .getByRole("button", { name: "登入", exact: true })
    .click();
  await expect(page).toHaveURL(/\/app\/(?:forms|profile)$/);
  await page.getByRole("link", { name: /回到官網/ }).click();
  await expect(page).toHaveURL(/\/$/);
  if (isMobile)
    await page.getByRole("button", { name: "開啟導覽選單" }).click();
  await expect(page.getByRole("link", { name: /的工作台$/ })).toBeVisible();
  await expect(
    page.getByRole("link", { name: "管理工作台", exact: true }),
  ).toHaveCount(0);
  await page.getByRole("link", { name: /的工作台$/ }).click();
  await expect(page).toHaveURL(/\/app\/(?:forms|profile)$/);
  if (isMobile) {
    await expect(
      page.getByRole("button", { name: "我的服務", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "管理工作台", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "我的服務", exact: true }).click();
  }
  await expect(
    page.getByRole("link", { name: "排程管理", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("link", { name: "角色權限", exact: true }),
  ).toHaveCount(0);
  await page.getByRole("link", { name: "個人資料與奉獻", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: /個人/ }).first(),
  ).toBeVisible();
  const size = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth,
  }));
  expect(size.scroll).toBeLessThanOrEqual(size.width + 2);
});
