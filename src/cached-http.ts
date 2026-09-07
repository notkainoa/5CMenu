import { handleRequest } from './http';
import { californiaDate } from './dates';
import type { Env } from './types';

/** A short edge cache reduces repeated KV reads; failure falls back to the API. */
export async function cachedRequest(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
  if (request.method !== 'GET' && request.method !== 'HEAD') return handleRequest(request, env);
  const now = new Date();
  const url = new URL(request.url);
  // Include the current service day in the internal cache key, even for explicit
  // dates, so yesterday's now-unsupported URL cannot survive midnight.
  const keyUrl = new URL(url);
  keyUrl.searchParams.append('__cache_service_day', californiaDate(now));
  const key = new Request(keyUrl, { method: 'GET' });
  const cache = (caches as CacheStorage & { default: Cache }).default;
  try {
    const cached = await cache.match(key);
    if (cached) {
      const etag = cached.headers.get('etag');
      const conditional = request.headers.get('if-none-match');
      if (etag && conditional?.split(',').some(tag => tag.trim() === '*' || tag.trim().replace(/^W\//, '') === etag)) {
        return new Response(null, { status: 304, headers: cached.headers });
      }
      return request.method === 'HEAD' ? new Response(null, { status: cached.status, headers: cached.headers }) : cached;
    }
  } catch { /* Cache eviction or failure must not take the API offline. */ }
  const response = await handleRequest(request, env, now);
  if (request.method === 'GET' && response.status === 200) {
    ctx.waitUntil(cache.put(key, response.clone()).catch(() => undefined));
  }
  return response;
}
