import test from 'node:test';
import assert from 'node:assert/strict';
import { boundedText } from '../src/providers/response';
import { refreshBonAppetit } from '../src/providers/bon-appetit';

test('response bounds apply to streamed bytes even without Content-Length', async () => {
  await assert.rejects(boundedText(new Response('too long'), 4), /size limit/);
  assert.equal(await boundedText(new Response('é'), 2), 'é');
  await assert.rejects(boundedText(new Response('é'), 1), /size limit/);
});
test('declared oversize responses are cancelled before reading them', async () => {
  let cancelled = false;
  const stream = new ReadableStream({ cancel() { cancelled = true; } });
  await assert.rejects(boundedText(new Response(stream, { headers: { 'content-length': '1000' } }), 10), /size limit/);
  assert.equal(cancelled, true);
});
test('Bon Appetit fetches carry cancellation signals and abort failures remain unavailable', async () => {
  const result = await refreshBonAppetit('collins', ['2026-09-06'], undefined, async (_url, init) => {
    assert.ok(init?.signal instanceof AbortSignal);
    throw new DOMException('Aborted', 'AbortError');
  });
  assert.deepEqual(result.days, []);
  assert.equal(result.errors?.['2026-09-06'].code, 'SOURCE_FETCH_FAILED');
});
