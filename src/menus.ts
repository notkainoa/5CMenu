import { HALLS, type HallId, type HallMenu, type Snapshot } from './types';

export const STALE_AFTER_MS = 90 * 60 * 1000;
export function unavailableMenu(hall: HallId, date: string): HallMenu {
  return {
    hall, date, status: 'unavailable', sourceUrl: HALLS.find(entry => entry.id === hall)!.sourceUrl,
    lastCheckedAt: null, lastSuccessfulCheckAt: null, menuUpdatedAt: null, meals: null,
    error: { code: 'MENU_UNAVAILABLE', message: 'No verified menu is available for this date.' },
  };
}
export function menuFor(snapshot: Snapshot | null, hall: HallId, date: string, now: Date): HallMenu {
  const stored = snapshot?.menus[date]?.[hall];
  if (!stored || stored.date !== date) return unavailableMenu(hall, date);
  const menu = { ...stored };
  if (menu.status !== 'unavailable' && (!menu.lastSuccessfulCheckAt || now.getTime() - Date.parse(menu.lastSuccessfulCheckAt) > STALE_AFTER_MS)) {
    menu.status = 'stale';
    menu.error ??= { code: 'CHECK_OVERDUE', message: 'The menu has not been verified recently. Showing the last successful menu for this date.' };
  }
  return menu;
}
