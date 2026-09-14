import test from 'node:test';
import assert from 'node:assert/strict';
import { refreshPomona } from '../src/providers/pomona';
import { refreshSodexo } from '../src/providers/sodexo';
import type { Fetcher } from '../src/types';

const jsonResponse = (body: unknown, init: ResponseInit = {}) => new Response(JSON.stringify(body), {
  status: 200,
  headers: { 'content-type': 'application/json', ...init.headers },
  ...init,
});

test('Sodexo reads exact dates and preserves known item fields', async () => {
  const calls: string[] = [];
  const fetcher: Fetcher = async input => {
    calls.push(String(input));
    return jsonResponse([{
      name: 'LUNCH',
      groups: [{ name: 'CHEF &amp; CORNER', items: [{
        formalName: 'Rice &amp; Beans', description: 'With vegetables',
        isVegan: true, isVegetarian: true, calories: '320',
      }] }],
    }]);
  };
  const result = await refreshSodexo('hoch', ['2026-09-06', '2026-09-07'], undefined, fetcher);
  assert.equal(calls.length, 2);
  assert.match(calls[1], /date=2026-09-07$/);
  assert.deepEqual(result.days[0], {
    date: '2026-09-06', status: 'ok', meals: [{ name: 'LUNCH', period: 'lunch', stations: [{
      name: 'CHEF & CORNER', items: [{ name: 'Rice & Beans', description: 'With vegetables', vegan: true, vegetarian: true, calories: 320 }],
    }] }],
  });
});

test('Sodexo reuses parsed results when the downloaded body is unchanged', async () => {
  const body = [{ name: 'DINNER', groups: [{ name: 'Grill', items: [{ formalName: 'Tacos' }] }] }];
  const first = await refreshSodexo('hoch', ['2026-09-06'], undefined, async () => jsonResponse(body));
  const second = await refreshSodexo('hoch', ['2026-09-06'], first.state, async () => jsonResponse(body));
  assert.deepEqual(second, first);
});

test('Sodexo reparses cached dates after a parser version bump so period is published', async () => {
  const body = [{ name: 'DINNER', groups: [{ name: 'Grill', items: [{ formalName: 'Tacos' }] }] }];
  const first = await refreshSodexo('hoch', ['2026-09-06'], undefined, async () => jsonResponse(body));
  const stale = structuredClone(first.state) as { provider: string; dates: Record<string, { hash: string; day: { meals: Array<{ period?: string }> } }> };
  delete (stale as { version?: number }).version;
  delete stale.dates['2026-09-06'].day.meals[0].period;
  const second = await refreshSodexo('hoch', ['2026-09-06'], stale, async () => jsonResponse(body));
  assert.equal(second.days[0].meals[0].period, 'dinner');
});

test('Sodexo treats an empty date as unpublished and rejects malformed data', async () => {
  const missing = await refreshSodexo('hoch', ['2026-09-06'], undefined, async () => jsonResponse([]));
  assert.deepEqual(missing.days, []);
  for (const response of [jsonResponse([{ name: 'Lunch', groups: [{ name: 'Grill', items: [{}] }] }]), new Response('login', { headers: { 'content-type': 'text/html' } })]) {
    const failed = await refreshSodexo('hoch', ['2026-09-06'], undefined, async () => response);
    assert.deepEqual(failed.days, []);
    assert.equal(failed.errors?.['2026-09-06'].code, 'SOURCE_FETCH_FAILED');
  }
});

function pomonaJsonp(menu: unknown): string {
  const addLabels = (value: unknown) => ({ nutrients: 'Calories (kcal)~CAL|Fat (g)~TL', ...(value as object) });
  return `/**/ menuData(${JSON.stringify({ EatecExchange: { menu: Array.isArray(menu) ? menu.map(addLabels) : addLabels(menu) } })});`;
}

const recipe = {
  '@shortName': 'Vegetable Curry', '@category': 'Expo Station', '@itemDailyComment': 'With rice',
  '@nutrients': '245.5|10|2',
  dietaryChoices: { dietaryChoice: [{ '@id': 'Vegetarian', '#text': 'Yes' }, { '@id': 'Vegan', '#text': 'No' }] },
};

test('Pomona groups records into meals and stations without dropping recipes', async () => {
  const menu = [
    { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe: [recipe, { ...recipe, '@shortName': 'Second Curry' }] } },
    { '@servedate': '20260906', '@mealperiodname': 'Dinner', '@menubulletin': '', recipes: { recipe: { ...recipe, '@category': 'Mainline' } } },
  ];
  const response = new Response(pomonaJsonp(menu), { headers: { 'content-type': 'application/json', etag: '"abc"', 'last-modified': 'Sun, 06 Sep 2026 19:00:00 GMT' } });
  const result = await refreshPomona('frank', ['2026-09-06'], undefined, async () => response);
  assert.equal(result.days[0].meals.length, 2);
  assert.equal(result.days[0].meals[0].name, 'Lunch');
  assert.equal(result.days[0].meals[0].period, 'lunch');
  assert.equal(result.days[0].meals[1].name, 'Dinner');
  assert.equal(result.days[0].meals[1].period, 'dinner');
  assert.equal(result.days[0].meals[0].stations[0].items.length, 2);
  assert.deepEqual(result.days[0].meals[0].stations[0].items[0], {
    name: 'Vegetable Curry', description: 'With rice', vegetarian: true, vegan: false, calories: 245.5,
  });
  assert.equal(result.state.etag, '"abc"');
});

test('Pomona only marks a date closed from an explicit closed record', async () => {
  const closed = { '@servedate': '20260906', '@mealperiodname': 'Closed', '@menubulletin': 'Closed', recipes: { closed: 'date' } };
  const result = await refreshPomona('oldenborg', ['2026-09-06', '2026-09-07'], undefined, async () => new Response(pomonaJsonp(closed), { headers: { 'content-type': 'application/json' } }));
  assert.deepEqual(result.days, [{ date: '2026-09-06', status: 'closed', meals: [] }]);
  assert.equal(result.days.some(day => day.date === '2026-09-07'), false);
});

test('Pomona conditional requests reuse verified parsed state on 304', async () => {
  const menu = { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe } };
  const first = await refreshPomona('frary', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(menu), {
    headers: { 'content-type': 'application/json', etag: '"same"', 'last-modified': 'Sun, 06 Sep 2026 19:00:00 GMT' },
  }));
  let checkedHeaders: Headers | undefined;
  const second = await refreshPomona('frary', ['2026-09-06'], first.state, async (_input, init) => {
    checkedHeaders = new Headers(init?.headers);
    return new Response(null, { status: 304 });
  });
  assert.equal(checkedHeaders?.get('if-none-match'), '"same"');
  assert.equal(checkedHeaders?.get('if-modified-since'), 'Sun, 06 Sep 2026 19:00:00 GMT');
  assert.deepEqual(second, first);
});

test('Pomona reparses cached feeds after a parser version bump so period is published', async () => {
  const menu = { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe } };
  const first = await refreshPomona('frary', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(menu), {
    headers: { 'content-type': 'application/json', etag: '"same"' },
  }));
  const stale = structuredClone(first.state) as { provider: string; hash: string; days: Array<{ meals: Array<{ period?: string }> }> };
  delete (stale as { version?: number }).version;
  delete stale.days[0].meals[0].period;
  const second = await refreshPomona('frary', ['2026-09-06'], stale, async () => new Response(pomonaJsonp(menu), {
    headers: { 'content-type': 'application/json' },
  }));
  assert.equal(second.days[0].meals[0].period, 'lunch');
});

test('Pomona refetches when a 304 cache covers only part of the requested window', async () => {
  const menu = { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe } };
  const first = await refreshPomona('frary', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(menu), {
    headers: { 'content-type': 'application/json', etag: '"same"' },
  }));
  const statuses: number[] = [];
  const expanded = await refreshPomona('frary', ['2026-09-06', '2026-09-07'], first.state, async (_input, init) => {
    if (new Headers(init?.headers).has('if-none-match')) {
      statuses.push(304);
      return new Response(null, { status: 304 });
    }
    statuses.push(200);
    return new Response(pomonaJsonp([
      menu,
      { '@servedate': '20260907', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe } },
    ]), { headers: { 'content-type': 'application/json' } });
  });
  assert.deepEqual(statuses, [304, 200]);
  assert.deepEqual(expanded.days.map(day => day.date), ['2026-09-06', '2026-09-07']);
});

test('Pomona refetches on 304 when cached days miss the entire requested window', async () => {
  const closed = { '@servedate': '20260503', '@mealperiodname': 'Closed', '@menubulletin': 'Closed', recipes: { closed: 'date' } };
  const september = { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: { recipe } };
  const first = await refreshPomona('oldenborg', ['2026-05-03'], undefined, async () => new Response(pomonaJsonp(closed), {
    headers: { 'content-type': 'application/json', etag: '"old"' },
  }));
  const statuses: number[] = [];
  const expanded = await refreshPomona('oldenborg', ['2026-09-06'], first.state, async (_input, init) => {
    if (new Headers(init?.headers).has('if-none-match')) {
      statuses.push(304);
      return new Response(null, { status: 304 });
    }
    statuses.push(200);
    return new Response(pomonaJsonp(september), { headers: { 'content-type': 'application/json' } });
  });
  assert.deepEqual(statuses, [304, 200]);
  assert.deepEqual(expanded.days.map(day => day.date), ['2026-09-06']);
  assert.equal(expanded.days[0].status, 'ok');
});

test('Pomona rejects bad wrappers and recipe records instead of publishing empty menus', async () => {
  await assert.rejects(
    refreshPomona('frank', ['2026-09-06'], undefined, async () => new Response('{}', { headers: { 'content-type': 'application/json' } })),
    /lacks EatecExchange/,
  );
  const noRecipes = { '@servedate': '20260906', '@mealperiodname': 'Lunch', '@menubulletin': '', recipes: {} };
  await assert.rejects(
    refreshPomona('frank', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(noRecipes), { headers: { 'content-type': 'application/json' } })),
    /no recipes/,
  );
});

test('Pomona unknown dietary answers remain absent and nutrition follows column labels', async () => {
  const menu = { '@servedate': '20260906', '@mealperiodname': 'Lunch', nutrients: 'Fat (g)~TL|Calories (kcal)~CAL', recipes: { recipe: {
    ...recipe, '@nutrients': '8|200', dietaryChoices: { dietaryChoice: [{ '@id': 'Vegan', '#text': 'Unknown' }] },
  } } };
  const result = await refreshPomona('frank', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(menu), { headers: { 'content-type': 'application/json' } }));
  const item = result.days[0].meals[0].stations[0].items[0];
  assert.equal(item.calories, 200);
  assert.equal(item.vegan, undefined);
});

test('Pomona closed records do not hide other published meals on that date', async () => {
  const menu = [
    { '@servedate': '20260906', '@mealperiodname': 'Closed', '@menubulletin': 'Closed' },
    { '@servedate': '20260906', '@mealperiodname': 'Lunch', recipes: { recipe } },
  ];
  const result = await refreshPomona('frank', ['2026-09-06'], undefined, async () => new Response(pomonaJsonp(menu), { headers: { 'content-type': 'application/json' } }));
  assert.equal(result.days[0].status, 'ok');
  assert.equal(result.days[0].meals[0].name, 'Lunch');
  assert.equal(result.days[0].meals[0].period, 'lunch');
});

test('Sodexo keeps unknown meal names and omits period', async () => {
  const result = await refreshSodexo('hoch', ['2026-09-06'], undefined, async () => jsonResponse([{
    name: 'Snack Window',
    groups: [{ name: 'Main', items: [{ formalName: 'Rice' }] }],
  }]));
  assert.deepEqual(result.days[0].meals[0], { name: 'Snack Window', stations: [{ name: 'Main', items: [{ name: 'Rice' }] }] });
});

test('Sodexo date failures preserve other dates and never invent whitespace calories', async () => {
  const result = await refreshSodexo('hoch', ['2026-09-06', '2026-09-07'], undefined, async input => {
    if (String(input).includes('2026-09-07')) throw new Error('offline');
    return jsonResponse([{ name: 'Lunch', groups: [{ name: 'Main', items: [{ formalName: 'Rice', calories: '  ' }] }] }]);
  });
  assert.equal(result.days.length, 1);
  assert.equal(result.days[0].meals[0].stations[0].items[0].calories, undefined);
  assert.equal(result.errors?.['2026-09-07'].code, 'SOURCE_FETCH_FAILED');
});
