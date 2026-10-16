export function scheduleColor(value?: string | null): string {
  return typeof value === "string" && /^#[0-9a-f]{6}$/i.test(value)
    ? value
    : "#3d8768";
}
