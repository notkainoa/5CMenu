import test from 'node:test';
import assert from 'node:assert/strict';
import { californiaDate, isValidDate, supportedDates } from '../src/dates';
import { menuFor } from '../src/menus';
import { refreshMenus } from '../src/refresh';
import { readSnapshot, validMeals } from '../src/storage';
import { HALLS, type ParsedDay, type RefreshHall, type SnapshotStore } from '../src/types';

class MemoryStore implements SnapshotStore {
  value: string | null = null;
  writes = 0;
  async get() { return this.value; }
  async put(_key: string, value: string) { this.value = value; this.writes++; }
}
const now = new Date('2026-09-06T19:00:00Z');
function day(date: string, name = 'Soup'): ParsedDay {
  return { date, status: 'ok', meals: [{ name: 'Lunch', stations: [{ name: 'Soup', items: [{ name }] }] }] };
}
const provider: RefreshHall = async (_hall, dates) => ({ days: dates.map(date => day(date)), state: { hash: 'same' } });

test('California calendar dates handle UTC rollover, leap years, and both DST transitions', () => {
  assert.equal(californiaDate(new Date('2026-09-07T06:59:59Z')), '2026-09-06');
  assert.equal(californiaDate(new Date('2026-09-07T07:00:00Z')), '2026-09-07');
  assert.deepEqual(supportedDates(new Date('2026-03-08T09:00:00Z')), [
    '2026-03-08', '2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-14',
  ]);
  assert.deepEqual(supportedDates(new Date('2026-11-01T08:00:00Z')), [
    '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07',
  ]);
  assert.equal(isValidDate('2024-02-29'), true);
  for (const invalid of ['2026-02-29', '2026-04-31', '2026-1-01', '2026-01-01x', 'foo']) assert.equal(isValidDate(invalid), false);
});

test('one refresh writes one snapshot for every hall and date; unchanged data preserves update time', async () => {
  const store = new MemoryStore();
  const first = await refreshMenus({ MENUS: store }, provider, now);
  assert.equal(store.writes, 1);
  assert.deepEqual(Object.keys(first.menus).sort(), ['2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12']);
  assert.equal(Object.keys(first.menus['2026-09-06']).length, 7);
  const later = new Date('2026-09-06T20:00:00Z');
  const second = await refreshMenus({ MENUS: store }, provider, later);
  assert.equal(store.writes, 2);
  assert.equal(second.menus['2026-09-06'].collins?.menuUpdatedAt, now.toISOString());
  assert.equal(second.menus['2026-09-06'].collins?.lastSuccessfulCheckAt, later.toISOString());
  assert.deepEqual(await readSnapshot(store), JSON.parse(store.value!));
});

test('partial failure retains same-day data, marks only failed hall stale, and recovers', async () => {
  const store = new MemoryStore();
  await refreshMenus({ MENUS: store }, provider, now);
  const failed = await refreshMenus({ MENUS: store }, async (hall, ...args) => {
    if (hall === 'collins') throw new Error('upstream timeout');
    return provider(hall, ...args);
  }, new Date('2026-09-06T20:00:00Z'));
  assert.equal(failed.menus['2026-09-06'].collins?.status, 'stale');
  assert.equal(failed.menus['2026-09-06'].collins?.lastSuccessfulCheckAt, now.toISOString());
  assert.equal(failed.menus['2026-09-06'].frary?.status, 'ok');
  const recovered = await refreshMenus({ MENUS: store }, provider, new Date('2026-09-06T21:00:00Z'));
  assert.equal(recovered.menus['2026-09-06'].collins?.status, 'ok');
  assert.equal(recovered.menus['2026-09-06'].collins?.error, undefined);
});

test('new date never inherits an earlier date and old dates are pruned', async () => {
  const store = new MemoryStore();
  await refreshMenus({ MENUS: store }, async () => ({ days: [day('2026-09-06')], state: {} }), now);
  const next = await refreshMenus({ MENUS: store }, async () => { throw new Error('offline'); }, new Date('2026-09-07T08:00:00Z'));
  assert.equal(next.menus['2026-09-06'], undefined);
  assert.equal(next.menus['2026-09-07'].collins?.status, 'unavailable');
  assert.equal(next.menus['2026-09-07'].collins?.meals, null);
  assert.equal(menuFor(next, 'collins', '2026-09-06', now).status, 'unavailable');
});

test('missing publication and invalid empty menus cannot erase valid meals', async () => {
  const store = new MemoryStore();
  await refreshMenus({ MENUS: store }, provider, now);
  const missing = await refreshMenus({ MENUS: store }, async () => ({ days: [], state: {} }), now);
  assert.equal(missing.menus['2026-09-06'].collins?.status, 'stale');
  assert.equal(missing.menus['2026-09-06'].collins?.error?.code, 'MENU_NOT_PUBLISHED');
  const empty = await refreshMenus({ MENUS: store }, async () => ({ days: [{ date: '2026-09-06', status: 'ok', meals: [] }], state: {} }), now);
  assert.equal(empty.menus['2026-09-06'].collins?.status, 'stale');
  assert.ok(empty.menus['2026-09-06'].collins?.meals?.length);
});

test('explicit closure is accepted but closed menus with items are rejected', async () => {
  const store = new MemoryStore();
  const closed = await refreshMenus({ MENUS: store }, async () => ({ days: [{ date: '2026-09-06', status: 'closed', meals: [] }], state: {} }), now);
  assert.equal(closed.menus['2026-09-06'].collins?.status, 'closed');
  assert.deepEqual(closed.menus['2026-09-06'].collins?.meals, []);
  const bad = await refreshMenus({ MENUS: store }, async () => ({ days: [{ ...day('2026-09-06'), status: 'closed' }], state: {} }), now);
  assert.equal(bad.menus['2026-09-06'].collins?.status, 'stale');
});

test('missed hourly run makes menus stale without mutating storage or substituting dates', async () => {
  const store = new MemoryStore();
  const snapshot = await refreshMenus({ MENUS: store }, provider, now);
  assert.equal(menuFor(snapshot, 'collins', '2026-09-06', new Date('2026-09-06T21:00:00Z')).status, 'stale');
  assert.equal(snapshot.menus['2026-09-06'].collins?.status, 'ok');
  assert.equal(store.writes, 1);
});

test('storage failures are propagated and never trigger a destructive replacement', async () => {
  let calls = 0;
  const store = new MemoryStore();
  store.value = '{broken json';
  await assert.rejects(refreshMenus({ MENUS: store }, async (...args) => { calls++; return provider(...args); }, now));
  assert.equal(calls, 0);
  assert.equal(store.writes, 0);
  const failingStore = { get: async () => null, put: async () => { throw new Error('KV unavailable'); } };
  await assert.rejects(refreshMenus({ MENUS: failingStore }, provider, now), /KV unavailable/);
});

test('provider concurrency is bounded and previous state is passed through', async () => {
  const store = new MemoryStore();
  await refreshMenus({ MENUS: store }, provider, now);
  let active = 0; let peak = 0;
  await refreshMenus({ MENUS: store }, async (_hall, dates, previous) => {
    assert.equal(previous?.hash, 'same');
    peak = Math.max(peak, ++active);
    await new Promise(resolve => setTimeout(resolve, 1));
    active--;
    return { days: dates.map(date => day(date)), state: {} };
  }, now);
  assert.equal(peak, 2);
  assert.equal(HALLS.length, 7);
});

test('a snapshot over 5 MB fails the refresh without writing', async () => {
  const store = new MemoryStore();
  const items = Array.from({ length: 80 }, (_, index) => ({ name: `${index}:${'n'.repeat(1990)}` }));
  await assert.rejects(refreshMenus({ MENUS: store }, async (_hall, dates) => ({
    days: dates.map(date => ({ date, status: 'ok' as const, meals: [{ name: 'Lunch', stations: [{ name: 'Main', items }] }] })),
    state: { hash: 'huge' },
  }), now), /5 MB/);
  assert.equal(store.writes, 0);
});

test('per-date provider errors publish successful dates and mark the rest failed', async () => {
  const store = new MemoryStore();
  const failed = await refreshMenus({ MENUS: store }, async (_hall, dates) => ({
    days: [day(dates[0])],
    state: { hash: 'partial' },
    errors: { [dates[1]]: { code: 'SOURCE_FETCH_FAILED', message: 'The menu source could not be fetched or validated.' } },
  }), now);
  assert.equal(failed.menus['2026-09-06'].collins?.status, 'ok');
  assert.equal(failed.menus['2026-09-07'].collins?.status, 'unavailable');
  assert.equal(failed.menus['2026-09-07'].collins?.error?.code, 'SOURCE_FETCH_FAILED');
});

test('stored snapshots reject invalid meal times and closed menus that still list food', async () => {
  const store = new MemoryStore();
  await refreshMenus({ MENUS: store }, provider, now);
  const snapshot = JSON.parse(store.value!) as { menus: Record<string, { collins: { meals: { startTime?: string }[]; status: string } }> };
  snapshot.menus['2026-09-06'].collins.meals[0].startTime = 'noon';
  store.value = JSON.stringify(snapshot);
  await assert.rejects(readSnapshot(store), /Invalid stored menu/);

  const closedStore = new MemoryStore();
  await refreshMenus({ MENUS: closedStore }, async () => ({
    days: [{ date: '2026-09-06', status: 'closed' as const, meals: [] }],
    state: {},
  }), now);
  const closed = JSON.parse(closedStore.value!) as { menus: Record<string, { collins: { status: string; meals: unknown } }> };
  closed.menus['2026-09-06'].collins.meals = [{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta' }] }] }];
  closedStore.value = JSON.stringify(closed);
  await assert.rejects(readSnapshot(closedStore), /Invalid stored menu/);
});

test('stored items accept featured true or false and reject non-boolean featured', () => {
  const meals = [{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta', featured: false }] }] }];
  assert.equal(validMeals(meals), true);
  assert.equal(validMeals([{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta', featured: true }] }] }]), true);
  assert.equal(validMeals([{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta', featured: 1 }] }] }]), false);
});

test('stored meals accept known period tokens and reject unknown labels', () => {
  assert.equal(validMeals([{ name: 'DINNER', period: 'dinner', stations: [{ name: 'Main', items: [{ name: 'Pasta' }] }] }]), true);
  assert.equal(validMeals([{ name: 'Snack', stations: [{ name: 'Main', items: [{ name: 'Pasta' }] }] }]), true);
  assert.equal(validMeals([{ name: 'DINNER', period: 'DINNER', stations: [{ name: 'Main', items: [{ name: 'Pasta' }] }] }]), false);
});

test('stored items accept explicit diet no values and reject non-boolean diet flags', () => {
  assert.equal(validMeals([{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta', glutenFree: false, plantBased: true }] }] }]), true);
  assert.equal(validMeals([{ name: 'Lunch', stations: [{ name: 'Main', items: [{ name: 'Pasta', glutenFree: 'yes' }] }] }]), false);
});
