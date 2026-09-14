import { HALLS, type Meal, type Snapshot, type SnapshotStore } from './types';
import { isValidDate, validTime } from './dates';
import { DIET_FLAGS } from './diet';
import { MEAL_PERIODS } from './periods';

export const SNAPSHOT_KEY = 'snapshot:v1';
export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function validMeals(value: unknown): value is Meal[] {
  return Array.isArray(value) && value.length <= 30 && value.every(meal =>
    isRecord(meal) && typeof meal.name === 'string' && meal.name.trim().length > 0 &&
    (meal.period === undefined || typeof meal.period === 'string' && (MEAL_PERIODS as readonly string[]).includes(meal.period)) &&
    [meal.startTime, meal.endTime].every(time => time === undefined || validTime(time)) &&
    Array.isArray(meal.stations) && meal.stations.length <= 200 && meal.stations.every(station =>
      isRecord(station) && typeof station.name === 'string' && station.name.trim().length > 0 &&
      Array.isArray(station.items) && station.items.length <= 2000 && station.items.every(item =>
        isRecord(item) && typeof item.name === 'string' && item.name.trim().length > 0 && item.name.length <= 2000 &&
        (item.description === undefined || typeof item.description === 'string') &&
        [item.featured, ...DIET_FLAGS.map(flag => item[flag])].every(flag => flag === undefined || typeof flag === 'boolean') &&
        (item.calories === undefined || typeof item.calories === 'number' && Number.isFinite(item.calories) && item.calories >= 0))));
}
function timestamp(value: unknown): boolean {
  return value === null || typeof value === 'string' && Number.isFinite(Date.parse(value));
}
export async function readSnapshot(store: SnapshotStore): Promise<Snapshot | null> {
  const raw = await store.get(SNAPSHOT_KEY);
  if (raw === null) return null;
  const value: unknown = JSON.parse(raw);
  if (!isRecord(value) || value.version !== 1 || typeof value.refreshedAt !== 'string' || !timestamp(value.refreshedAt) ||
    !isRecord(value.menus) || !isRecord(value.sources)) throw new Error('Invalid menu snapshot');
  for (const [date, halls] of Object.entries(value.menus)) {
    if (!isValidDate(date) || !isRecord(halls)) throw new Error('Invalid menu snapshot date');
    for (const [hall, menu] of Object.entries(halls)) {
      if (!HALLS.some(candidate => candidate.id === hall) || !isRecord(menu) || menu.hall !== hall || menu.date !== date ||
        !['ok', 'closed', 'stale', 'unavailable'].includes(String(menu.status)) || typeof menu.sourceUrl !== 'string' ||
        ![menu.lastCheckedAt, menu.lastSuccessfulCheckAt, menu.menuUpdatedAt].every(timestamp) ||
        (menu.status === 'unavailable' ? menu.meals !== null : !validMeals(menu.meals)) ||
        (menu.status === 'closed' && Array.isArray(menu.meals) && menu.meals.length !== 0) ||
        (menu.error !== undefined && (!isRecord(menu.error) || typeof menu.error.code !== 'string' || typeof menu.error.message !== 'string'))) {
        throw new Error('Invalid stored menu');
      }
    }
  }
  return value as unknown as Snapshot;
}
