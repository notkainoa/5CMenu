import { supportedDates } from './dates';
import { unavailableMenu } from './menus';
import { isRecord, readSnapshot, SNAPSHOT_KEY, validMeals } from './storage';
import { HALLS, type ApiError, type Env, type Fetcher, type HallId, type HallMenu, type ParsedDay, type ProviderResult, type RefreshHall, type Snapshot } from './types';

function validateResult(result: ProviderResult, dates: string[]): void {
  if (!result || !Array.isArray(result.days) || !isRecord(result.state)) throw new Error('Invalid provider result');
  const seen = new Set<string>();
  if (result.errors !== undefined && (!isRecord(result.errors) || Object.entries(result.errors).some(([date, error]) => !dates.includes(date) || !isRecord(error) || typeof error.code !== 'string' || typeof error.message !== 'string'))) throw new Error('Invalid provider errors');
  for (const day of result.days) {
    if (!dates.includes(day.date) || seen.has(day.date) || result.errors?.[day.date] || !['ok', 'closed'].includes(day.status) || !validMeals(day.meals)) throw new Error('Invalid provider menu');
    const items = day.meals.flatMap(meal => meal.stations).flatMap(station => station.items);
    if (day.status === 'ok' && items.length === 0 || day.status === 'closed' && day.meals.length !== 0) throw new Error('Menu lacks evidence of availability');
    seen.add(day.date);
  }
}
function success(hall: HallId, day: ParsedDay, old: HallMenu | undefined, checkedAt: string): HallMenu {
  const changed = !old || JSON.stringify(old.meals) !== JSON.stringify(day.meals) || (old.status === 'closed') !== (day.status === 'closed') && old.status !== 'stale';
  return {
    ...unavailableMenu(hall, day.date), status: day.status, meals: day.meals,
    lastCheckedAt: checkedAt, lastSuccessfulCheckAt: checkedAt,
    menuUpdatedAt: changed ? checkedAt : old!.menuUpdatedAt ?? checkedAt,
    error: undefined,
  };
}
function failure(hall: HallId, date: string, old: HallMenu | undefined, checkedAt: string, missing: boolean, error?: ApiError): HallMenu {
  const menu = old?.date === date && old.meals !== null ? { ...old, status: 'stale' as const } : unavailableMenu(hall, date);
  return { ...menu, lastCheckedAt: checkedAt, error: error ?? {
    code: missing ? 'MENU_NOT_PUBLISHED' : 'SOURCE_FETCH_FAILED',
    message: missing ? 'The source did not publish a verified menu for this date.' : 'The menu source could not be fetched or validated.',
  } };
}

/** One scheduled writer; public requests never call this function. */
export async function refreshMenus(env: Env, refreshHall: RefreshHall, now = new Date(), fetcher: Fetcher = globalThis.fetch): Promise<Snapshot> {
  // A storage read failure must not overwrite the only good snapshot.
  const previous = await readSnapshot(env.MENUS);
  const dates = supportedDates(now);
  const checkedAt = now.toISOString();
  const next: Snapshot = { version: 1, refreshedAt: checkedAt, menus: Object.fromEntries(dates.map(date => [date, {}])), sources: {} };
  let cursor = 0;
  async function work(): Promise<void> {
    while (cursor < HALLS.length) {
      const hall = HALLS[cursor++].id;
      try {
        const result = await refreshHall(hall, dates, previous?.sources[hall], fetcher);
        validateResult(result, dates);
        next.sources[hall] = result.state;
        for (const date of dates) {
          const day = result.days.find(candidate => candidate.date === date);
          const old = previous?.menus[date]?.[hall];
          next.menus[date][hall] = day ? success(hall, day, old, checkedAt) : failure(hall, date, old, checkedAt, true, result.errors?.[date]);
        }
      } catch (error) {
        console.warn(JSON.stringify({ event: 'source_failed', hall, message: error instanceof Error ? error.message : 'Unknown provider failure' }));
        if (previous?.sources[hall]) next.sources[hall] = previous.sources[hall];
        for (const date of dates) next.menus[date][hall] = failure(hall, date, previous?.menus[date]?.[hall], checkedAt, false);
      }
    }
  }
  // At most two halls in flight; provider adapters must also bound their fetches.
  await Promise.all([work(), work()]);
  const serialized = JSON.stringify(next);
  if (new TextEncoder().encode(serialized).byteLength > 5 * 1024 * 1024) throw new Error('Snapshot exceeds the 5 MB application limit');
  await env.MENUS.put(SNAPSHOT_KEY, serialized);
  console.info(JSON.stringify({ event: 'refresh_complete', at: checkedAt, bytes: serialized.length }));
  return next;
}
