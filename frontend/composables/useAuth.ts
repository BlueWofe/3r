import type { User } from "~/types";
export function useAuth() {
  const current = useState<User | null>("auth-user", () => null);
  const initialized = useState<boolean>("auth-initialized", () => false);
  const user = computed(() => current.value);
  const loggedIn = computed(() => !!current.value);
  const can = (permission: string) =>
    !!current.value &&
    (current.value.permissions.includes(permission) ||
      current.value.permissions.includes("*"));
  async function refresh() {
    try {
      current.value = (await api<{ user: User }>("/auth/me")).user;
    } catch {
      current.value = null;
    } finally {
      initialized.value = true;
    }
    return current.value;
  }
  async function login(phone: string, password: string) {
    current.value = (
      await api<{ user: User }>("/auth/login", {
        method: "POST",
        body: { phone, password },
      })
    ).user;
    initialized.value = true;
    return current.value;
  }
  async function logout() {
    await api("/auth/logout", { method: "POST" });
    current.value = null;
    initialized.value = true;
    await navigateTo("/");
  }
  return { user, loggedIn, initialized, can, refresh, login, logout };
}
