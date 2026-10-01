type ApiOptions = {
  method?: string;
  body?: any;
  csrf?: boolean;
  headers?: Record<string, string>;
  [key: string]: any;
};
export async function api<T>(
  path: string,
  options: ApiOptions = {},
): Promise<T> {
  const config = useRuntimeConfig();
  const server = import.meta.server;
  const baseURL = (
    server ? config.apiInternalUrl : config.public.apiBase
  ) as string;
  const endpoint = path;
  const method = String(options.method || "GET").toUpperCase();
  const mutate = !["GET", "HEAD"].includes(method);
  const nuxt = useNuxtApp();
  const notice = useState("save-notice", () => ({ serial: 0, message: "" }));
  const isDataChange =
    mutate &&
    /^(\/(cases|groups|contact-inquiries|prisons|class-templates|sessions|assignments|contents|products|orders|shipping-settings|users|roles|forms|meetings|resources|settings|integrations)(\/|$)|\/auth\/(profile|change-phone)$)/.test(
      path,
    ) &&
    !/\/(preview|quote|export)$/.test(path);
  try {
    const request = async () => {
      const csrf =
        mutate && options.csrf !== false
          ? (
              await $fetch<any>("/auth/csrf", {
                baseURL,
                credentials: "include",
              })
            ).csrf_token
          : "";
      return await $fetch<T>(endpoint, {
        ...options,
        baseURL,
        credentials: "include",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          ...(csrf ? { "X-CSRF-TOKEN": csrf } : {}),
          ...(options.headers || {}),
        },
      } as any);
    };
    let result: T;
    try {
      result = await request();
    } catch (e: any) {
      if ((e?.statusCode || e?.response?.status) === 419 && mutate)
        result = await request();
      else throw e;
    }
    if (!server && isDataChange) {
      notice.value = { serial: notice.value.serial + 1, message: "已更新" };
      // Refresh cached public content as well as each editor's own list reload.
      void nuxt.runWithContext(() => refreshNuxtData()).catch(() => {});
    }
    return result;
  } catch (error: any) {
    const code = error?.statusCode || error?.response?.status;
    const msg =
      error?.data?.message ||
      (code === 409
        ? "資料已被其他人更新，請重新整理後再試。"
        : code === 403
          ? "您沒有此操作權限。"
          : "連線或資料處理失敗，請稍後再試。");
    throw Object.assign(new Error(msg), { code, details: error?.data?.errors });
  }
}
export function useApiError() {
  const error = ref("");
  return {
    error,
    async run<T>(fn: () => Promise<T>) {
      error.value = "";
      try {
        return await fn();
      } catch (e: any) {
        error.value = e.message;
        throw e;
      }
    },
  };
}
