import type { ApiError, MenuItem, ParsedDay, RefreshHall, SourceState } from '../types';
import { withMealPeriod } from '../periods';

const API_URL = 'https://api-prd.sodexomyway.net/v0.2/data/menu/13147001/15258';
// This is the public browser key shipped by hmc.sodexomyway.com.
const API_KEY = '68717828-b754-420d-9488-4c37cb7d7ef7';
const MAX_BYTES = 2 * 1024 * 1024;
const TIMEOUT_MS = 15_000;
const STATE_VERSION = 2;

type JsonRecord = Record<string, unknown>;
interface CachedDate { hash: string; day?: ParsedDay }
interface SodexoState extends SourceState {
  provider: 'sodexo';
  version: typeof STATE_VERSION;
  dates: Record<string, CachedDate>;
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function optionalString(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined;
  const trimmed = value.trim();
  return trimmed || undefined;
}

function decodeEntities(value: string): string {
  return value.replace(/&(#(?:x[0-9a-f]+|\d+)|amp|lt|gt|quot|apos);/gi, (match, entity: string) => {
    const lower = entity.toLowerCase();
    if (lower === 'amp') return '&';
    if (lower === 'lt') return '<';
    if (lower === 'gt') return '>';
    if (lower === 'quot') return '"';
    if (lower === 'apos') return "'";
    const radix = lower.startsWith('#x') ? 16 : 10;
    const digits = lower.slice(radix === 16 ? 2 : 1);
    const codePoint = Number.parseInt(digits, radix);
    return Number.isFinite(codePoint) && codePoint >= 0 && codePoint <= 0x10ffff ? String.fromCodePoint(codePoint) : match;
  });
}

function parseCalories(value: unknown): number | undefined {
  if (typeof value !== 'string' && typeof value !== 'number') return undefined;
  if (typeof value === 'string' && !/^\d+(?:\.\d+)?$/.test(value.trim())) return undefined;
  const calories = Number(value);
  return Number.isFinite(calories) && calories >= 0 ? calories : undefined;
}

function parseItem(value: unknown): MenuItem {
  if (!isRecord(value) || typeof value.formalName !== 'string' || !value.formalName.trim()) {
    throw new Error('Sodexo returned a menu item without a name');
  }
  const item: MenuItem = { name: decodeEntities(value.formalName.trim()) };
  const description = optionalString(value.description);
  if (description) item.description = decodeEntities(description);
  if (typeof value.isVegan === 'boolean') item.vegan = value.isVegan;
  if (typeof value.isVegetarian === 'boolean') item.vegetarian = value.isVegetarian;
  if (typeof value.isPlantBased === 'boolean') item.plantBased = value.isPlantBased;
  if (typeof value.isMindful === 'boolean') item.mindful = value.isMindful;
  if (typeof value.isGlutenFree === 'boolean') item.glutenFree = value.isGlutenFree;
  const calories = parseCalories(value.calories);
  if (calories !== undefined) item.calories = calories;
  return item;
}

function parseDay(value: unknown, date: string): ParsedDay | undefined {
  if (!Array.isArray(value)) throw new Error('Sodexo response is not a meal array');
  if (value.length === 0) return undefined;

  const meals = value.map(mealValue => {
    if (!isRecord(mealValue) || typeof mealValue.name !== 'string' || !mealValue.name.trim() || !Array.isArray(mealValue.groups)) {
      throw new Error('Sodexo returned a malformed meal');
    }
    const stations = mealValue.groups.map(groupValue => {
      if (!isRecord(groupValue) || typeof groupValue.name !== 'string' || !groupValue.name.trim() || !Array.isArray(groupValue.items)) {
        throw new Error('Sodexo returned a malformed station');
      }
      return { name: decodeEntities(groupValue.name.trim()), items: groupValue.items.map(parseItem) };
    });
    return withMealPeriod({ name: decodeEntities(mealValue.name.trim()), stations });
  });

  const itemCount = meals.reduce((sum, meal) => sum + meal.stations.reduce((stationSum, station) => stationSum + station.items.length, 0), 0);
  return itemCount > 0 ? { date, status: 'ok', meals } : undefined;
}

async function sha256(text: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
}

async function boundedText(response: Response): Promise<string> {
  const declared = Number(response.headers.get('content-length'));
  if (Number.isFinite(declared) && declared > MAX_BYTES) throw new Error('Sodexo response is too large');
  if (!response.body) {
    const text = await response.text();
    if (new TextEncoder().encode(text).byteLength > MAX_BYTES) throw new Error('Sodexo response is too large');
    return text;
  }
  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > MAX_BYTES) {
      await reader.cancel();
      throw new Error('Sodexo response is too large');
    }
    chunks.push(value);
  }
  const bytes = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder().decode(bytes);
}

function previousState(value: SourceState | undefined): SodexoState | undefined {
  if (!isRecord(value) || value.provider !== 'sodexo' || value.version !== STATE_VERSION || !isRecord(value.dates)) return undefined;
  return value as SodexoState;
}

async function fetchDate(date: string, cached: CachedDate | undefined, fetcher: typeof fetch): Promise<CachedDate> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);
  try {
    const response = await fetcher(`${API_URL}?date=${encodeURIComponent(date)}`, {
      headers: { 'API-Key': API_KEY, Accept: 'application/json' },
      signal: controller.signal,
    });
    if (!response.ok) throw new Error(`Sodexo returned HTTP ${response.status}`);
    if (!response.headers.get('content-type')?.toLowerCase().includes('application/json')) throw new Error('Sodexo returned a non-JSON response');
    const text = await boundedText(response);
    const hash = await sha256(text);
    if (cached?.hash === hash) return cached;
    let json: unknown;
    try { json = JSON.parse(text); } catch { throw new Error('Sodexo returned invalid JSON'); }
    return { hash, day: parseDay(json, date) };
  } finally {
    clearTimeout(timeout);
  }
}

export const refreshSodexo: RefreshHall = async (hall, dates, previous, fetcher) => {
  if (hall !== 'hoch') throw new Error(`Sodexo does not provide ${hall}`);
  const old = previousState(previous);
  const entries: Record<string, CachedDate> = {};
  const successfulDates = new Set<string>();
  const errors: Record<string, ApiError> = {};
  for (const date of dates) {
    try {
      entries[date] = await fetchDate(date, old?.dates[date], fetcher);
      successfulDates.add(date);
    } catch (error) {
      if (old?.dates[date]) entries[date] = old.dates[date];
      console.warn(JSON.stringify({ event: 'source_date_failed', provider: 'sodexo', date, message: error instanceof Error ? error.message : 'Unknown error' }));
      errors[date] = { code: 'SOURCE_FETCH_FAILED', message: 'The menu source could not be fetched or validated.' };
    }
  }
  return {
    days: dates.flatMap(date => successfulDates.has(date) && entries[date]?.day ? [entries[date].day!] : []),
    state: { provider: 'sodexo', version: STATE_VERSION, dates: entries },
    ...(Object.keys(errors).length > 0 ? { errors } : {}),
  };
};
