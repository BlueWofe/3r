const fallbackName = "中華復甦更新發展協會";
const fallbackLogo = "/images/association-backend-logo.png";

export function useAssociationBranding() {
  const { data } = useAsyncData(
    "auth-brand-settings",
    () => api<any>("/public/contact").catch(() => null),
    { server: false },
  );
  const settings = computed(() => data.value?.data || data.value || null);
  const associationName = computed(() => settings.value?.association_name || fallbackName);
  const logoUrl = computed(() => settings.value?.logo_url || fallbackLogo);
  return { associationName, logoUrl };
}
