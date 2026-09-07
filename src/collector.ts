import { DurableObject } from 'cloudflare:workers';
import { refreshMenus } from './refresh';
import { refreshHall } from './providers';
import type { Env } from './types';

export interface CollectionSummary { refreshedAt: string; failedDates: number; }

/** Only the scheduled Worker can reach this object through its private binding. */
export class MenuCollector extends DurableObject<Env> {
  private running?: Promise<CollectionSummary>;

  async refresh(): Promise<CollectionSummary> {
    // Overlapping invocations share one writer rather than racing to replace KV.
    if (this.running) return this.running;
    this.running = this.run();
    try { return await this.running; }
    finally { this.running = undefined; }
  }

  private async run(): Promise<CollectionSummary> {
    const now = new Date();
    const hour = now.toISOString().slice(0, 13);
    const last = await this.ctx.storage.get<{ hour: string; summary: CollectionSummary }>('last-run');
    if (last?.hour === hour) return last.summary;
    const snapshot = await refreshMenus(this.env, refreshHall, now);
    const failedDates = Object.values(snapshot.menus).flatMap(halls => Object.values(halls)).filter(menu => menu?.error?.code === 'SOURCE_FETCH_FAILED').length;
    const summary = { refreshedAt: snapshot.refreshedAt, failedDates };
    // Persist only after the complete KV snapshot is successfully saved.
    await this.ctx.storage.put('last-run', { hour, summary });
    return summary;
  }
}
