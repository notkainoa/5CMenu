# 5C Menu API overhaul

## Purpose and agreed scope

Replace the incomplete PHP backend with a public TypeScript API that runs on Cloudflare Workers. This repository is API-only. Any web or mobile app, including apps made by other developers, should be able to request predictable JSON without scraping dining websites itself.

The legacy code covers Hoch, Malott, McConnell, Collins, Frank, Frary, and Oldenborg. Its HTTP wrapper and DatabaseMenuParser are missing. Provider URLs and parsing assumptions must be verified against current live sources; copying PHP behavior is not proof of correctness. Preserve the legacy source and Apache license as reference.

## Product requirements

- Public read-only JSON endpoints: `/v1/halls`, `/v1/menus`, and `/v1/menus/:hall`.
- Both menu endpoints accept `?date=YYYY-MM-DD`, defaulting to today in America/Los_Angeles. Canonical `mcconnell` also accepts legacy `mcconnel`.
- The combined endpoint returns every hall, including explicit failure entries. One provider failure must not remove healthy halls.
- An hourly background task checks actual menu feeds. Use HTTP validators when trustworthy and available; otherwise download once, compare content fingerprints, and parse that same response only if changed. A static landing page is not sufficient evidence that a separately hosted menu feed is unchanged.
- Public requests only read saved data. They never trigger arbitrary scraping or accept upstream URLs.
- Preserve the last validated menu for its exact service date when an update fails. Never use yesterday's menu for today. Missing dates return unavailable, not an invented closure.
- Distinguish `ok`, `stale`, `closed`, and `unavailable`. An empty or malformed upstream response is not sufficient evidence of closure.
- Include service date, source links, latest attempted check, latest successful check, menu update time, and a machine-readable error where relevant. Unknown nutrition or dietary information stays absent rather than becoming false or zero.
- Do not silently remove menu items using the old UI-oriented filtering and truncation rules.

## Architecture and routine choices

One Worker handles HTTP reads and an hourly scheduled dispatcher. The dispatcher calls one private SQLite-backed `MenuCollector` Durable Object, which performs collection with its larger CPU allowance. Provider modules hide differences between dining systems. A small shared domain model describes dates, meals, stations, items, and availability. The object coalesces overlapping invocations and saves the last completed UTC hour so duplicate triggers do not repeat work. It returns only a tiny summary to the ordinary Worker.

Use one versioned KV snapshot for the supported menu window and internal refresh state. An updater reads the previous snapshot, checks at most two halls concurrently with bounded response sizes and timeouts, validates and merges results per hall/date, then replaces the snapshot once. Saving check timestamps on unchanged runs is intentional. This is normally 24 writes/day rather than a write per visitor. Public requests never write KV or call providers. Retain today through six days ahead in the public snapshot. Cache bounded parsed source inputs separately inside that snapshot so a multi-day feed can reuse its already parsed dates after midnight. No unbounded history. Explicit unsupported dates return HTTP 400.

KV updates can take time to propagate. An hourly menu service tolerates this. Responses and edge-cache entries live at most 60 seconds, shortened before California midnight. Internal cache keys include the current California date, so midnight cannot return yesterday as today's menu. CORS and ETags support browser clients and conditional requests. Missing dates return HTTP 503; combined responses remain 200 if any hall has data. A successful check older than 90 minutes becomes stale even if the scheduler stopped entirely. Response caching can add up to another minute before that status is visible.

The update should avoid reparsing unchanged inputs but must still validate date coverage at rollover. Conditional HTTP responses cannot make a nonexistent date available. Never replace good data with a login page, malformed data, or an unexplained empty menu.

## Free-plan budget and limits

At 20,000 actual API calls/day, HTTP requests are below the Workers Free allowance of 100,000/day shared across the account. One snapshot read per request stays below KV's 100,000 reads/day. Hourly writes are about 24/day, below 1,000/day. Deleting stored records does not refund writes; expiration and overwriting control stored bytes, not the daily operation counter.

CPU is a separate constraint: Workers Free allows 10 ms per ordinary HTTP or scheduled invocation. The actual collector runs inside a Durable Object with a default 30-second CPU allowance, also available on the free plan. Local replay measurements showed roughly 56–93 ms for full collection and 31–34 ms for unchanged downloaded bodies, so putting all parsing inside an ordinary Cron handler was rejected. The HTTP API measured roughly 1–2 ms locally before edge caching. These are local elapsed measurements with network responses replayed, not billed Cloudflare CPU measurements.

The Durable Object runs about 24 times/day against a free allowance of 100,000 requests/day and 13,000 GB-seconds/day. At 20 seconds per collection including network waiting for seven dates, expected duration is about 62 GB-seconds/day. Store one small completion record per run in its SQLite storage. No external scheduler is required. GitHub Actions is optional CI only, not menu collection. Production quota and latency measurements still require deployment to the user's account.

References checked during planning:

- https://developers.cloudflare.com/workers/platform/limits/
- https://developers.cloudflare.com/kv/platform/pricing/
- https://developers.cloudflare.com/kv/concepts/how-kv-works/
- https://developers.cloudflare.com/durable-objects/platform/limits/
- https://developers.cloudflare.com/durable-objects/platform/pricing/

## Implementation order and parallel work

1. Main agent writes this plan and establishes shared types, API behavior, tooling, and file ownership.
2. In parallel, a GPT-5.6 Sol agent researches live provider endpoints and documents evidence. A GPT-5.6 Terra agent can implement HTTP routing and tests against the fixed contract. The main agent implements the scheduled updater, storage, dates, and failure policy.
3. Once research establishes formats, a GPT-5.6 Sol or Terra agent implements provider adapters and fixture tests independently of the HTTP router. Use a GPT-5.6 Luna agent for bounded documentation or independent review after contracts settle. Medium reasoning suits mechanical tasks; high reasoning suits source interpretation and state transitions. Do not use Astra unless a concrete unresolved task requires it.
4. Integrate adapters and updater after both compile. Test end-to-end in the Workers runtime with controlled upstream responses, local KV, and the real SQLite Durable Object. Verify duplicate scheduled triggers and public edge caching.
5. Run live source checks separately from deterministic tests, recording dates, HTTP outcomes, item counts, and unavailable sources. Verify actual menus, not merely HTTP 200.
6. Run type checking, all deterministic tests, deployment bundling, and a local HTTP/scheduled smoke test. Inspect final changes and address defects. Update this plan and README with actual behavior and remaining external limits.

Agents must use `rtk` for shell commands, edit only assigned files with apply_patch, preserve unrelated user edits, and report evidence rather than claims of absolute correctness. Shared contracts must be agreed before parallel implementation. The main agent owns integration and final verification. Avoid redundant research, repeated broad test runs, and overlapping edits.

Start new subagents with `fork_turns: "none"`: an empty conversation plus a focused task prompt, relevant file paths, interfaces, constraints, and acceptance criteria. This avoids paying to repeat the full conversation. Include additional history only when its value outweighs its input-token cost. The first research agent inherited history; subsequent implementation agents started with only their task prompts. User explicitly prefers this approach.

## Required verification

- Valid/malformed dates, unsupported halls, methods, routes, query parameters, California midnight and daylight-saving boundaries.
- Combined and individual response agreement; public JSON contains no internal source state.
- First startup with no snapshot; malformed snapshot; KV read/write failure.
- Changed, unchanged, HTTP 304, timeout, HTTP error, malformed and incomplete provider data.
- Same-date fallback, missing-date error, no previous-day substitution, partial provider outage, explicit closure, recovery after outage, missed scheduler staleness.
- A provider error does not prevent other sources from publishing; persistence occurs once per completed refresh.
- Content validation, source date matching, no invented nutrition, all known stations/items preserved.
- Public API requests perform no provider fetches or KV writes. They may populate the short response cache. Request validators and CORS work on success and errors.
- Live sources and Workers CPU/free-tier eligibility are reported independently of fixture-test results.

## Completion and deployment

Deliver the implementation, tests, documented API examples and deployment commands, an environment/configuration example without secrets, a live-source diagnostic command, and a reproducible verification record. A request to implement is not a guarantee that provider sites will remain available or that bugs are impossible. Deployment/account provisioning and live Cloudflare metrics may require credentials not present locally; prepare and verify the deployment bundle regardless, and clearly name any remaining external step.

## Execution record

Implemented the API, three provider adapters, hourly dispatcher, private Durable Object, KV snapshot merging, short response caching, diagnostics, tests, configuration, and documentation. No runtime package dependencies are needed.

Research found the old Bon Appetit API now rejects unauthenticated requests. Its dated public pages embed JSON item data and dated meal sections, which the new adapter reads. Hoch uses a current public Sodexo API. Pomona uses current Eatec JSON/JSONP feeds. Oldenborg's feed contains only old dates as of verification, so it reports unavailable rather than yesterday's or last semester's menu. Six other halls returned actual menus for both requested dates.

Sol agents researched and implemented providers; Terra implemented HTTP routing and tests. A Luna review and the provider agents subsequently hit a model usage limit. The main agent completed their saved work, corrected integration tests, reviewed date/failure/nutrition behavior, and performed final verification. No Astra subagents were used. See docs/verification.md for reproducible evidence and remaining external deployment steps.
