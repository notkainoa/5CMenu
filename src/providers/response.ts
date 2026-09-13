/** Bound decoded bytes too: Content-Length can be absent or describe compressed data. */
export async function boundedText(response: Response, maxBytes: number): Promise<string> {
  if (Number(response.headers.get('content-length')) > maxBytes) {
    await response.body?.cancel();
    throw new Error('Menu response exceeds size limit');
  }
  if (!response.body) return '';
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let bytes = 0;
  const chunks: string[] = [];
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.byteLength;
      if (bytes > maxBytes) {
        await reader.cancel();
        throw new Error('Menu response exceeds size limit');
      }
      chunks.push(decoder.decode(value, { stream: true }));
    }
    chunks.push(decoder.decode());
    return chunks.join('');
  } finally { reader.releaseLock(); }
}
