export function useWorkspaceNavigation() {
  const { can } = useAuth();
  const schedule = () => can("schedule.read.own") || can("schedule.read.all");
  const adminLinks = [
    [
      "/app/admin/schedule",
      "排程管理",
      () => can("schedule.create.all") || can("schedule.update.all"),
    ],
    [
      "/app/admin/classes",
      "班別管理",
      () =>
        can("schedule.read.all") ||
        can("schedule.create.all") ||
        can("schedule.update.all"),
    ],
    [
      "/app/admin/users",
      "人員與角色",
      () => can("users.read.all") || can("users.update.all"),
    ],
    ["/app/admin/roles", "角色權限", () => can("roles.manage.all")],
    ["/app/admin/prisons", "監所管理", () => can("prisons.manage.all")],
    [
      "/app/admin/content?section=news",
      "最新消息",
      () => can("content.read.all") || can("content.update.all"),
    ],
    [
      "/app/admin/content?section=testimony",
      "見證分享",
      () => can("content.read.all") || can("content.update.all"),
    ],
    [
      "/app/admin/content?section=pages",
      "協會頁面",
      () => can("content.read.all") || can("content.update.all"),
    ],
    [
      "/app/admin/products",
      "產品管理",
      () => can("content.read.all") || can("content.update.all"),
    ],
    [
      "/app/admin/cases",
      "個案紀錄",
      () => can("cases.read.all") || can("cases.read.assigned"),
    ],
    [
      "/app/admin/meetings",
      "會議管理",
      () => can("meetings.read.all") || can("meetings.read.own"),
    ],
    ["/app/admin/forms", "表單中心", () => can("forms.read.all")],
    [
      "/app/admin/reports",
      "服務報表",
      () => can("reports.read.all") || can("reports.read.own"),
    ],
    ["/app/admin/settings", "協會設定", () => can("settings.manage.all")],
  ] as const;

  const managementLinks = computed(() =>
    adminLinks.filter((link) => link[2]()),
  );
  const managementGroups = computed(() =>
    [
      {
        title: "課務與關懷",
        icon: "calendar",
        paths: ["schedule", "classes", "prisons", "cases", "reports"],
      },
      { title: "官網內容", icon: "edit", paths: ["content", "products"] },
      { title: "會務與資源", icon: "folder", paths: ["meetings", "forms"] },
      {
        title: "人員與系統",
        icon: "settings",
        paths: ["users", "roles", "settings"],
      },
    ]
      .map((group) => ({
        ...group,
        links: managementLinks.value.filter((link) =>
          group.paths.includes(link[0].split("?")[0]!.split("/").pop()!),
        ),
      }))
      .filter((group) => group.links.length),
  );
  const servicePath = computed(() =>
    schedule() ? "/app" : can("forms.read.own") ? "/app/forms" : "/app/profile",
  );
  const managementPath = computed(() => managementLinks.value[0]?.[0] ?? null);
  const workspacePath = computed(() =>
    schedule()
      ? servicePath.value
      : (managementPath.value ?? servicePath.value),
  );
  return {
    schedule,
    managementLinks,
    managementGroups,
    servicePath,
    managementPath,
    workspacePath,
  };
}
