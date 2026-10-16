export function taipeiDate(date = new Date()): string {
  const parts = new Intl.DateTimeFormat("en", {
    timeZone: "Asia/Taipei", year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(date);
  const part = (type: string) => parts.find(item => item.type === type)?.value;
  return `${part("year")}-${part("month")}-${part("day")}`;
}

export function taipeiDateTime(value?: string): string {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return `${taipeiDate(date)} ${new Intl.DateTimeFormat("zh-TW", {
    timeZone: "Asia/Taipei", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23",
  }).format(date)}（台北時間）`;
}
