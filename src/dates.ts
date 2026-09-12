export const TIMEZONE = 'America/Los_Angeles';
export const WINDOW_DAYS = 7;
const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit' });
export function californiaDate(now: Date): string {
  const parts = formatter.formatToParts(now);
  const value = (type: string) => parts.find(part => part.type === type)!.value;
  return `${value('year')}-${value('month')}-${value('day')}`;
}
export function isValidDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(`${value}T12:00:00Z`)) && new Date(`${value}T12:00:00Z`).toISOString().slice(0, 10) === value;
}
export function validTime(value: unknown): value is string {
  return typeof value === 'string' && /^(?:[01]?\d|2[0-3]):[0-5]\d$/.test(value);
}
export function supportedDates(now: Date): string[] {
  const today = californiaDate(now);
  // Advance calendar dates in UTC, not 24-hour steps in California across DST.
  const cursor = new Date(`${today}T12:00:00Z`);
  const dates = [today];
  for (let offset = 1; offset < WINDOW_DAYS; offset += 1) {
    cursor.setUTCDate(cursor.getUTCDate() + 1);
    dates.push(cursor.toISOString().slice(0, 10));
  }
  return dates;
}
