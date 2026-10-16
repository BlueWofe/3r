export default defineNuxtRouteMiddleware(async (to) => {
  const protectedRoute = to.path === "/change-password" || to.path.startsWith("/app");
  if (!protectedRoute) return;
  const { user, initialized, refresh } = useAuth();
  if (!initialized.value) await refresh();
  if (!user.value) {
    return navigateTo({ path: "/login", query: { returnTo: to.fullPath } });
  }
  if (user.value.must_change_password && to.path !== "/change-password") {
    return navigateTo("/change-password");
  }
  if (!user.value.must_change_password && to.path === "/change-password") {
    const { workspacePath } = useWorkspaceNavigation();
    return navigateTo(workspacePath.value);
  }
});
