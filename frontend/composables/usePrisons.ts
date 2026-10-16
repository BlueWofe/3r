export type PrisonOption = { id: number; name: string; active?: boolean };
export function usePrisons() {
  const prisons = ref<PrisonOption[]>([]),
    error = ref("");
  async function load() {
    try {
      prisons.value =
        (await api<{ data: PrisonOption[] }>("/prisons/options")).data || [];
      error.value = "";
    } catch (caught: any) {
      error.value = caught.message;
    }
  }
  const availableFor = (current?: number | null) =>
    prisons.value.filter((p) => p.active !== false || p.id === current);
  return { prisons, error, load, availableFor };
}
