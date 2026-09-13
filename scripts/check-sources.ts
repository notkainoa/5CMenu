import process from 'node:process';
import { refreshHall } from '../src/providers';
import { supportedDates } from '../src/dates';
import { HALLS } from '../src/types';

const DIAGNOSTIC_BYTE_LIMIT = 4 * 1024 * 1024;

async function countedBytes(response: Response, limit: number): Promise<number> {
  if (!response.body) return 0;
  const reader = response.body.getReader();
  let total = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) return total;
    total += value.byteLength;
    if (total > limit) {
      await reader.cancel();
      throw new Error(`Diagnostic clone exceeded ${limit} bytes`);
    }
  }
}

const now = new Date();
const dates = supportedDates(now);
console.log(`Live source check at ${now.toISOString()}; requested dates: ${dates.join(', ')}`);
let failures = 0;
for (const hall of HALLS) {
  const start = performance.now();
  try {
    let requests = 0; let bytes = 0;
    const measuredFetch: typeof fetch = async (input, init) => {
      requests++;
      const response = await fetch(input, init);
      // Count the clone only for diagnostics; production does not duplicate downloads.
      bytes += await countedBytes(response.clone(), DIAGNOSTIC_BYTE_LIMIT);
      return response;
    };
    const result = await refreshHall(hall.id, dates, undefined, measuredFetch);
    const menus = dates.map(date => {
      const day = result.days.find(day => day.date === date);
      const items = day?.meals.flatMap(meal => meal.stations).flatMap(station => station.items).length ?? 0;
      return { date, status: day?.status ?? 'unavailable', meals: day?.meals.length ?? 0, items };
    });
    if (menus.some(menu => menu.status === 'unavailable')) failures++;
    console.log(JSON.stringify({ hall: hall.id, requests, bytes, wallMs: Math.round(performance.now() - start), menus }));
  } catch (error) {
    failures++;
    console.log(JSON.stringify({ hall: hall.id, error: error instanceof Error ? error.message : String(error), wallMs: Math.round(performance.now() - start) }));
  }
}
// Diagnostic failures are visible to CI, even when the API correctly reports unavailable.
if (failures) {
  console.error(`${failures} halls have unavailable dates or failed sources. This is a live-data result, not a deterministic test failure.`);
  process.exitCode = 1;
}
