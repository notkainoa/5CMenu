import { cachedRequest } from './cached-http';
import { MenuCollector } from './collector';
import type { Env } from './types';
export { MenuCollector };
export interface WorkerEnv extends Env { COLLECTOR: DurableObjectNamespace<MenuCollector>; }

export default {
  fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> { return cachedRequest(request, env, ctx); },
  async scheduled(_controller: ScheduledController, env: WorkerEnv): Promise<void> {
    // The ordinary Worker only dispatches work. Parsing runs with the Durable
    // Object's 30-second CPU allowance, including on Workers Free.
    const summary = await env.COLLECTOR.getByName('hourly-menu-collector').refresh();
    console.info(JSON.stringify({ event: 'scheduled_complete', ...summary }));
  },
};
