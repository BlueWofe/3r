import { test, expect } from "@playwright/test";
import {
  apiContext,
  createSession,
  csrfToken,
  json,
  login,
  mutate,
} from "./helpers";

test("concurrent substitute acceptance and stale edits cannot create duplicate assignments", async () => {
  const admin = await apiContext();
  const one = await apiContext();
  const two = await apiContext();
  try {
    await login(admin, "0900000001");
    const { data: users } = await json<{
      data: { id: number; phone: string }[];
    }>(await admin.get("/api/v1/users"));
    const original = users.find((u) => u.phone === "0900000002")!;
    const substitute = users.find((u) => u.phone === "0900000004")!;
    const session = await createSession(admin, original.id);
    await json(
      await mutate(
        admin,
        "post",
        `/api/v1/assignments/${session.assignments[0].id}/invite`,
        {
          version: session.version,
          teacher_id: substitute.id,
          reason: "並行代課驗證",
        },
      ),
    );
    await login(one, substitute.phone);
    await login(two, substitute.phone);
    const { data: invitations } = await json<{
      data: { id: number; session_id: number }[];
    }>(await one.get("/api/v1/invitations"));
    const invitation = invitations.find((i) => i.session_id === session.id)!;
    expect(invitation).toBeTruthy();
    const tokens = await Promise.all([csrfToken(one), csrfToken(two)]);
    const responses = await Promise.all(
      [one, two].map((api, i) =>
        api.post(`/api/v1/invitations/${invitation.id}/respond`, {
          data: { action: "accept" },
          headers: { "X-CSRF-TOKEN": tokens[i] },
        }),
      ),
    );
    expect(responses.map((r) => r.status()).sort()).toEqual([200, 409]);
    const current = await json<typeof session>(
      await admin.get(`/api/v1/sessions/${session.id}`),
    );
    expect(
      current.assignments.filter((a) => a.status === "assigned"),
    ).toHaveLength(1);
    expect(
      current.assignments.find((a) => a.status === "assigned")?.teacher_id,
    ).toBe(substitute.id);
    const token = await csrfToken(admin);
    const edits = await Promise.all(
      ["A", "B"].map((title) =>
        admin.put(`/api/v1/sessions/${session.id}`, {
          data: {
            version: current.version,
            title: `競爭修改${title}`,
            reason: "並行版本驗證",
          },
          headers: { "X-CSRF-TOKEN": token },
        }),
      ),
    );
    expect(edits.map((r) => r.status()).sort()).toEqual([200, 409]);
  } finally {
    await Promise.all([admin.dispose(), one.dispose(), two.dispose()]);
  }
});

test("conflict is rejected unless an authorized administrator supplies an override reason", async () => {
  const admin = await apiContext();
  try {
    await login(admin, "0900000001");
    const { data: users } = await json<{
      data: { id: number; phone: string }[];
    }>(await admin.get("/api/v1/users"));
    const teacher = users.find((u) => u.phone === "0900000002")!;
    const existing: any = await createSession(admin, teacher.id);
    const body = {
      title: "衝突驗證",
      prison: "示範",
      location: "示範",
      participant_count: 0,
      service_date: existing.service_date,
      start_time: existing.start_time,
      end_time: existing.end_time,
      teacher_ids: [teacher.id],
    };
    expect(
      (await mutate(admin, "post", "/api/v1/sessions", body)).status(),
    ).toBe(409);
    expect(
      (
        await mutate(admin, "post", "/api/v1/sessions", {
          ...body,
          override_conflict: true,
        })
      ).status(),
    ).toBe(422);
    expect(
      (
        await mutate(admin, "post", "/api/v1/sessions", {
          ...body,
          override_conflict: true,
          reason: "管理員確認示範重疊安排",
        })
      ).ok(),
    ).toBeTruthy();
  } finally {
    await admin.dispose();
  }
});
