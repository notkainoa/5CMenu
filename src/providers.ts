import { refreshBonAppetit } from './providers/bon-appetit';
import { refreshPomona } from './providers/pomona';
import { refreshSodexo } from './providers/sodexo';
import type { RefreshHall } from './types';

export const refreshHall: RefreshHall = (hall, dates, previous, fetcher) => {
  if (hall === 'hoch') return refreshSodexo(hall, dates, previous, fetcher);
  if (hall === 'collins' || hall === 'malott' || hall === 'mcconnell') {
    return refreshBonAppetit(hall, dates, previous, fetcher);
  }
  return refreshPomona(hall, dates, previous, fetcher);
};

