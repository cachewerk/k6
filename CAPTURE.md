# Building the request-capture mechanism

> Handoff instructions for a future Claude session. Read this top to bottom,
> then read `corpus/00000.json` and `corpus/manifest.json` — those are the
> canonical, hand-authored examples of the exact output you must produce.

## Goal

Capture real WordPress request data from a live site so it can be **replayed**
later without WordPress or MySQL (see `TODO.md` and `replay.js`). For each of
~10–100 sampled requests, record the interleaved timeline of **PHP compute
gaps**, **Redis commands** (reads + writes, verbatim), and **SQL queries**
(timing + full text), and write one JSON trace file per request into `corpus/`.

The replay side already exists in skeleton form (`stubs/replay-server.php`
serves traces by `?id=N`; `replay.js` drives it). Your job is the **capture**
side and a small **manifest build** step. Do **not** change the replay/metrics
behavior of `replay.js` or `lib/metrics.js`.

## Output format (authoritative)

The format is fully specified by the examples in this repo:
- `corpus/00000.json` — one captured request (a homepage hit: alloptions read,
  an options miss → SQL → SET, a main-query SQL, a pipelined write, a pipelined
  read). **Match this schema exactly.**
- `corpus/manifest.json` — the corpus index.

Key rules (all illustrated in the example):
- **One file per request**, zero-padded id (`00000.json`), id matches `?id=N`.
- `timeline` is an **ordered, interleaved** list of events. Durations there are
  **integer microseconds** (`us`). `summary` uses **float milliseconds**
  (`ms_*`) and field names that match the existing metric vocabulary in
  `lib/metrics.js` (`hit_ratio`, `bytes`, `sql_queries`, `redis_reads`/`writes`
  ↔ `store-reads`/`store-writes`, `ms_total` ↔ `ms-total`).
- Event types: `php` (compute gap → replay `usleep`), `redis` (one network
  round-trip carrying 1+ commands), `sql` (query → replay `usleep`, text kept
  for analysis).
- `redis.mode` ∈ `single` | `pipeline` | `multi` — preserves round-trip
  structure (round trips dominate latency; do not flatten pipelines).
- **Verbatim Redis values**, **binary-safe**: each element of `cmd.args` is a
  plain JSON string when it is valid UTF-8, otherwise the object
  `{ "b64": "<base64>" }`. Keys stay readable; serialized/compressed payloads
  (igbinary, lz4/zstd) are base64. Also record `value_bytes`/`ttl` on writes and
  `reply_bytes`/`hit` on reads.

Consider also emitting a `corpus/schema.json` (JSON Schema) and validating
output against it.

## What to build

1. A capture **mu-plugin** modeled on `stubs/k6-metrics.php` (same coding
   style, same `wp_object_cache` detection). Suggested:
   `stubs/k6-capture.php`.
2. A small **manifest build** step that scans the written trace files and emits
   `corpus/manifest.json`. Suggested: `bin/build-corpus.php` (or `.sh`). Keep
   capture and manifest separate so capture stays race-free (see below).

## Where the data comes from

### Redis commands + per-op timing — investigate first, then wrap

There is no portable per-command hook in PhpRedis. Options, in order of
preference:
1. **Check OCP first.** Object Cache Pro may already expose command logging /
   an analytics log you can tap (the user maintains OCP — confirm the current
   API rather than guessing). If it yields ordered commands with timing, use it.
2. **Decorate the client.** Otherwise wrap the drop-in's Redis client
   (`$wp_object_cache->redis_instance()` for Redis Object Cache; OCP's client
   accessor) in a logging proxy that, for every command, records
   `{ cmd, args, t_start, t_end }` using `hrtime(true)`.
   - For **pipelines/MULTI**, wrap the client's `pipeline()` / `multi()` so
     commands buffered until `exec()` are grouped into one `redis` event with
     the right `mode`; the round-trip `us` is measured across the `exec()`.
   - Capture `hit` from read replies (non-null/found), `reply_bytes` from the
     raw reply length, `value_bytes`/`ttl` from write args.

Do **not** use Redis `MONITOR` — it drops under load, hides pipelining, and
skews timing.

### SQL — `$wpdb->queries`

- Ensure `define('SAVEQUERIES', true)` for capture runs (document this; it adds
  overhead so it must be capture-only).
- After the request, read `$wpdb->queries`; each entry is
  `[0] => SQL, [1] => seconds (float), [2] => caller`. Convert seconds → `us`.
  `rows` is not in `$wpdb->queries` — leave it out or best-effort.

### Timing totals & PHP gaps

- `summary.ms_total` from `$timestart`/the OCP footnote (`ms-total`).
- **PHP gaps are derived, not measured directly:** order all Redis + SQL ops by
  their `hrtime` start, then a `php` event's `us` = gap between the previous
  op's end and the next op's start. Prepend the bootstrap gap (request start →
  first op) and append the tail gap (last op end → response). The sum of all
  `php` + `redis` + `sql` durations should ≈ `ms_total`.

## Sampling / triggering

Capture must be **opt-in per request** so you control exactly which URLs become
traces and avoid capturing admin/cron/REST noise:
- Trigger on a request header/cookie/query flag (e.g. `X-K6-Capture: 1` or
  `?k6_capture=1`), set only by the operator driving the capture (curl/k6 over a
  fixed URL list). Bail early otherwise.
- Reuse the bail-out guards from `stubs/k6-metrics.php` (skip WP-CLI, cron,
  AJAX, REST, JSON, robots, etc.).
- Write the trace on the `shutdown` hook (after the response, like
  `k6-metrics.php`'s `maybePrint`).

## Writing files & id assignment (avoid races)

Multiple PHP-FPM workers may capture concurrently — do **not** assign sequential
ids at write time. Instead:
- Capture writes to a raw dir with a collision-free name, e.g.
  `corpus/raw/<unixnanos>-<pid>-<rand>.json`, written atomically (temp file +
  `rename()`).
- `bin/build-corpus.php` later sorts raw files (by capture time or a provided
  URL order), assigns sequential ids `0..N-1`, writes `corpus/NNNNN.json`, and
  emits `corpus/manifest.json`. This is also where you can dedupe by URL or cap
  the count.

## Gotchas

- **Binary values**: OCP serializes (igbinary) and may compress (lz4/zstd) —
  values are binary; you MUST base64 them per the encoding rule. Verify a
  round-trip (`base64_decode` reproduces the exact bytes).
- **Persistent connections / object cache state**: capture reflects whatever was
  warm at request time; the `hit`/`miss` flags record that. Replay's warm-vs-cold
  handling is a separate concern (`TODO.md`).
- **SAVEQUERIES overhead**: capture-only; never enable on the benchmarked SUT.
- **Don't let capture itself pollute the trace**: exclude the plugin's own
  Redis/file I/O from the recorded ops.
- Keep file sizes sane: a homepage `alloptions` blob can be large; verbatim +
  base64 inflates ~33%. Fine for ~100 requests; gzip (`*.json.gz`) only if
  needed.

## Verification

1. Stand up (or point at) a WordPress site with an object cache drop-in.
2. Drive a known URL with the capture trigger; confirm a raw file appears.
3. Run `bin/build-corpus.php`; confirm `corpus/NNNNN.json` + `manifest.json`
   are produced and that a produced trace **validates against the example
   schema** (same keys/shape as `corpus/00000.json`).
4. Round-trip a `{b64}` value: `base64_decode` equals the original bytes.
5. Sanity-check timing: `sum(php+redis+sql us) ≈ ms_total * 1000`.
6. End-to-end: once `stubs/replay-server.php` is updated (later phase) to read
   `corpus/`, confirm `k6 run replay.js` drives the real corpus.

## Out of scope (later phases)

- The real replay executor (firing the captured commands via Relay/PhpRedis in
  `stubs/replay-server.php`).
- The PhpBench subject (separate track in `TODO.md`).
- Do **not** modify `replay.js` or `lib/metrics.js`.
