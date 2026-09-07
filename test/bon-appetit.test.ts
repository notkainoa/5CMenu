import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { parseBonAppetitPage, refreshBonAppetit } from '../src/providers/bon-appetit';
import type { Fetcher, SourceState } from '../src/types';

const DATE = '2026-09-06';
const TOMORROW = '2026-09-07';

function fixture(date = DATE, itemSuffix = ''): string {
  const items = {
    '101': {
      label: 'tofu &amp; greens', description: 'Ginger <br> sauce', special: 1,
      cor_icon: { '4': 'Vegan' }, nutrition_details: { calories: { value: '240' } },
    },
    '102': {
      label: 'mac &amp; cheese', description: '', special: 0,
      ordered_cor_icon: { first: { label: 'Vegetarian' } }, nutrition: { kcal: '0' },
    },
    '103': {
      label: `chef&#039;s choice${itemSuffix}`, description: 'No nutrition published', cor_icon: {}, nutrition: { kcal: '' },
    },
  };
  return `<!doctype html><html><body>
    <script>window.unrelated = { nested: "};" };</script>
    <script>(function () { Bamco.menu_items = ${JSON.stringify(items)}; Bamco.cor_icons = {}; })();</script>
    <section data-jump-nav-title="Breakfast" class="panel site-panel--daypart other">
      <div data-end-time="09:00" class="site-panel__daypart-container" data-end-date="${date}" data-start-time="07:30">
        <h2 class="site-panel__daypart-panel-title">Breakfast</h2>
        <h3 class="site-panel__daypart-station-title">Chef&#039;s Table &amp; Grill</h3>
        <div data-id="101" class="site-panel__daypart-item"><div data-id="999"></div></div>
        <div class="site-panel__daypart-item extra" data-id="102"></div>
        <h3 class="site-panel__daypart-station-title">Pantry</h3>
        <div class="site-panel__daypart-item" data-id="103"></div>
      </div>
    </section>
    <section class="site-panel--daypart" data-jump-nav-title="Lunch">
      <div class="site-panel__daypart-container" data-start-time="11:00" data-end-time="13:00" data-end-date="${date}">
        <h3 class="site-panel__daypart-station-title">Global</h3>
        <div class="site-panel__daypart-item" data-id="101"></div>
      </div>
    </section>
  </body></html>`;
}

function response(body: string, init: ResponseInit = {}): Response {
  return new Response(body, { status: 200, headers: { 'content-type': 'text/html; charset=UTF-8', ...init.headers }, ...init });
}

describe('parseBonAppetitPage', () => {
  it('preserves every rendered meal, station, and item without inventing optional data', () => {
    const day = parseBonAppetitPage(fixture(), DATE);
    assert.deepEqual(day, {
      date: DATE,
      status: 'ok',
      meals: [
        {
          name: 'Breakfast', startTime: '07:30', endTime: '09:00', stations: [
            { name: "Chef's Table & Grill", items: [
              { name: 'tofu & greens', description: 'Ginger sauce', vegan: true, calories: 240 },
              { name: 'mac & cheese', vegetarian: true, calories: 0 },
            ] },
            { name: 'Pantry', items: [{ name: "chef's choice", description: 'No nutrition published' }] },
          ],
        },
        {
          name: 'Lunch', startTime: '11:00', endTime: '13:00',
          stations: [{ name: 'Global', items: [{ name: 'tofu & greens', description: 'Ginger sauce', vegan: true, calories: 240 }] }],
        },
      ],
    });
    assert.equal(day?.meals[0].stations[0].items[0].vegetarian, undefined);
    assert.equal(day?.meals[0].stations[1].items[0].calories, undefined);
  });

  it('does not substitute sections belonging to another date', () => {
    assert.equal(parseBonAppetitPage(fixture(TOMORROW), DATE), null);
  });

  it('accepts only an exact-date explicit closure as closed', () => {
    const html = `<section class="site-panel--daypart" data-jump-nav-title="Closed"><div class="site-panel__daypart-container" data-end-date="${DATE}"><h2 class="site-panel__daypart-panel-title">Closed</h2></div></section>`;
    assert.deepEqual(parseBonAppetitPage(html, DATE), { date: DATE, status: 'closed', meals: [] });
    assert.equal(parseBonAppetitPage(html, TOMORROW), null);
  });

  it('rejects dated but unexplained empty or inconsistent menu markup', () => {
    const empty = `<script>Bamco.menu_items = {};</script><section class="site-panel--daypart" data-jump-nav-title="Dinner"><div class="site-panel__daypart-container" data-end-date="${DATE}"></div></section>`;
    assert.throws(() => parseBonAppetitPage(empty, DATE), /no menu items/);
    assert.throws(() => parseBonAppetitPage(fixture().replace('data-id="103"', 'data-id="missing"'), DATE), /unknown menu item missing/);
    assert.throws(() => parseBonAppetitPage(fixture().replace('Bamco.menu_items = {', 'Bamco.menu_items = {BROKEN'), DATE), /Invalid Bamco.menu_items JSON/);
  });
});

describe('refreshBonAppetit', () => {
  it('fetches today and tomorrow from the hall-specific dated URLs', async () => {
    const urls: string[] = [];
    const fetcher: Fetcher = async input => {
      const url = String(input);
      urls.push(url);
      const date = /\/(\d{4}-\d{2}-\d{2})\/$/.exec(url)?.[1];
      return response(fixture(date));
    };
    const result = await refreshBonAppetit('malott', [DATE, TOMORROW], undefined, fetcher);
    assert.deepEqual(urls, [
      `https://scripps.cafebonappetit.com/cafe/malott-dining-commons/${DATE}/`,
      `https://scripps.cafebonappetit.com/cafe/malott-dining-commons/${TOMORROW}/`,
    ]);
    assert.deepEqual(result.days.map(day => day.date), [DATE, TOMORROW]);
    assert.equal(JSON.stringify(result.state).includes('<html'), false);
    assert.match(JSON.stringify(result.state), /sha256:[a-f0-9]{64}/);
  });

  it('uses a validated Last-Modified cache on 304', async () => {
    const first = await refreshBonAppetit('collins', [DATE], undefined, async () =>
      response(fixture(), { headers: { 'content-type': 'text/html', 'last-modified': 'Sun, 06 Sep 2026 22:00:18 GMT' } }));
    let requestHeaders: Headers | undefined;
    const second = await refreshBonAppetit('collins', [DATE], first.state, async (_input, init) => {
      requestHeaders = new Headers(init?.headers);
      return new Response(null, { status: 304 });
    });
    assert.equal(requestHeaders?.get('if-modified-since'), 'Sun, 06 Sep 2026 22:00:18 GMT');
    assert.deepEqual(second, first);
  });

  it('reuses the cached parsed day when a 200 response digest is unchanged', async () => {
    const first = await refreshBonAppetit('mcconnell', [DATE], undefined, async () => response(fixture()));
    const cached = structuredClone(first.state) as SourceState;
    const pages = cached.pages as Record<string, { day: { meals: Array<{ stations: Array<{ items: Array<{ name: string }> }> }> } }>;
    pages[DATE].day.meals[0].stations[0].items[0].name = 'trusted cached parse';
    const second = await refreshBonAppetit('mcconnell', [DATE], cached, async () => response(fixture()));
    assert.equal(second.days[0].meals[0].stations[0].items[0].name, 'trusted cached parse');
  });

  it('ignores malformed state and does not send its validator', async () => {
    let requestHeaders: Headers | undefined;
    const malformed = {
      version: 1, provider: 'bon-appetit', hall: 'collins',
      pages: { [DATE]: { url: 'https://attacker.example/', digest: 'sha256:not-valid', lastModified: 'yesterday', day: { date: TOMORROW } } },
    };
    const result = await refreshBonAppetit('collins', [DATE], malformed, async (_input, init) => {
      requestHeaders = new Headers(init?.headers);
      return response(fixture());
    });
    assert.equal(requestHeaders?.has('if-modified-since'), false);
    assert.equal(result.days[0].date, DATE);
  });

  it('omits an unpublished exact date and rejects unsupported halls', async () => {
    const unpublished = await refreshBonAppetit('collins', [DATE], undefined, async () => response(fixture(TOMORROW)));
    assert.deepEqual(unpublished.days, []);
    assert.deepEqual((unpublished.state.pages as object), {});
    await assert.rejects(refreshBonAppetit('frank', [DATE], undefined, async () => response(fixture())), /not served/);
  });

  it('does not discard a successful date when another date fetch fails', async () => {
    const previous = await refreshBonAppetit('collins', [TOMORROW], undefined, async () => response(fixture(TOMORROW)));
    const result = await refreshBonAppetit('collins', [DATE, TOMORROW], previous.state, async input => {
      const url = String(input);
      return url.endsWith(`/${DATE}/`) ? response(fixture(DATE)) : response('upstream error', { status: 503 });
    });
    assert.deepEqual(result.days.map(day => day.date), [DATE]);
    assert.deepEqual(Object.keys(result.state.pages as object), [DATE, TOMORROW]);
    assert.deepEqual(result.errors, {
      [TOMORROW]: { code: 'SOURCE_FETCH_FAILED', message: 'The menu source could not be fetched or validated.' },
    });
  });
});
