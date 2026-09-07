import { refreshMenus } from '../src/refresh';
import { refreshHall } from '../src/providers';
import { handleRequest } from '../src/http';
import type { SnapshotStore } from '../src/types';

class MemoryStore implements SnapshotStore {
  value: string | null = null;
  async get() { return this.value; }
  async put(_key: string, value: string) { this.value = value; }
}
const responses = new Map<string, { body: string; headers: [string, string][]; status: number }>();
const recordingFetch: typeof fetch = async (input, init) => {
  const response = await fetch(input, init);
  const body = await response.text();
  const headers = [...response.headers.entries()];
  responses.set(String(input), { body, headers, status: response.status });
  return new Response(body, { headers, status: response.status });
};
const replayFetch: typeof fetch = async input => {
  const recorded = responses.get(String(input));
  if (!recorded) throw new Error(`Unrecorded benchmark URL: ${input}`);
  return new Response(recorded.body, { headers: recorded.headers, status: recorded.status });
};
const now = new Date();
const store = new MemoryStore();
await refreshMenus({ MENUS: store }, refreshHall, now, recordingFetch);
const summary: Record<string, unknown> = { at: now.toISOString(), sourceRequests: responses.size, snapshotBytes: new TextEncoder().encode(store.value!).byteLength };
const cold: number[] = []; const unchanged: number[] = [];
for (let i = 0; i < 5; i++) {
  const empty = new MemoryStore();
  let start = performance.now();
  await refreshMenus({ MENUS: empty }, refreshHall, now, replayFetch);
  cold.push(performance.now() - start);
  start = performance.now();
  await refreshMenus({ MENUS: empty }, refreshHall, now, replayFetch);
  unchanged.push(performance.now() - start);
}
const http: number[] = [];
for (let i = 0; i < 20; i++) {
  const start = performance.now();
  await (await handleRequest(new Request('https://menu.test/v1/menus'), { MENUS: store }, now)).text();
  http.push(performance.now() - start);
}
const stats = (values: number[]) => ({ min: Math.min(...values), median: [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)], max: Math.max(...values) });
Object.assign(summary, { coldRefreshMs: stats(cold), unchangedRefreshMs: stats(unchanged), combinedApiMs: stats(http) });
console.log(JSON.stringify(summary, null, 2));
console.log('Local elapsed timings with all upstream responses replayed in memory. These are not billed Cloudflare CPU measurements.');
