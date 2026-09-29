import type { User } from "~/types";
export function useAuth() {
  const current = useState<User | null>("auth-user", () => null);
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
  }
  async function logout() {
    await api("/auth/logout", { method: "POST" });
    current.value = null;
    await navigateTo("/");
  }
  return { user, loggedIn, can, refresh, login, logout };
}
