# WordPress benchmark simulation — TODO

## Goal

Benchmark a WordPress site's cache/Redis layer **without** standing up
WordPress, MySQL or doing real page rendering. We capture a small corpus of
real requests once, then *replay* them: sleep for the captured PHP compute
time and fire the captured Redis commands. This isolates the Redis/Relay layer
and lets every benchmark run execute an **identical, reproducible workload** so
systems (Relay vs PhpRedis vs Predis, Redis vs Valkey/KeyDB, single vs
clustered, etc.) can be compared apples-to-apples — including horizontally
scaled setups.

## Architecture

```
  (1) capture          (2) corpus            (3) replay endpoint        (4) driver
  ───────────          ──────────            ───────────────────        ──────────
  real WP request  →   N traces of      →    stateless PHP-FPM     ←──   k6 (replay.js)
  (one-off)            Redis cmds +          endpoint: id in,             fires ?id=N,
                       compute time +        replay trace, return         owns ordering
                       TTLs + batches        metrics                      & determinism
```

- **The server is dumb / stateless.** k6 decides which trace runs and in what
  order via `?id=N`; the server just executes trace N. All determinism lives in
  the driver, which keeps runs comparable and lets us shard across machines.
- **Closed-loop** by default (run as fast as the SUT allows) for capability
  comparison; ramping/constant scenarios available for saturation testing.

## What's done

- [x] `replay.js` — k6 driver. Deterministic, segment-safe trace selection;
      selectable scenarios (`fixed` / `ramping` / `constant` / `shared`) via
      `--env SCENARIO=`; configurable `VUS`, `TOTAL`, `TRACES`, `STAGES`, etc.
- [x] `stubs/replay-server.php` — dummy endpoint that fakes the work and returns
      the metric JSON shape `replay.js` parses. Placeholder for the real
      executor.
- [x] README section documenting usage.

## What needs to be done

### Capture (not the hard part — do later)
- [ ] Instrument the real Redis client (wrap Relay/PhpRedis as used by the
      object-cache drop-in) to log per request: ordered commands + args,
      **pipeline/MULTI batch boundaries**, TTLs, response sizes, and the
      **per-batch timing gaps** (not just one total). Tag each with a request id.
- [ ] Decide capture mechanism — client wrapper preferred over Redis `MONITOR`
      (MONITOR drops under load, hides pipelining, skews timing).
- [ ] Capture 100 representative requests → corpus file(s) (one per id).
- [ ] Decide corpus format (JSON/MessagePack) and how the server preloads it
      (static array / APCu) so request handling is pure CPU + Redis.

### Replay executor (replace the dummy)
- [ ] Implement the real replay in `stubs/replay-server.php` (or a small app):
      load trace by id, `usleep` the captured compute/gap times, fire the
      captured Redis commands via the **production client**, preserving batch
      structure & TTLs, and **consume responses** (so client-side
      deserialization / Relay's in-memory layer is exercised).
- [ ] Run behind real **nginx + PHP-FPM** (not `php -S`) for a realistic
      process model. Pin `pm.max_children` (sleeping workers hold slots) and
      use persistent Redis connections to match production.
- [ ] Decide warm vs cold cache: pre-warm pass, or discard first N iterations.
- [ ] Emit real metrics in the response (total_ms, redis_ms, cmd_count, hits,
      misses, bytes) — `replay.js` already parses these.

### Driver / orchestration
- [ ] Validate distributed runs with execution segments across >1 machine.
- [ ] Optional `bin/` wrapper for a concurrency sweep (e.g. VUS=50/100/200/400)
      to produce a capability curve.
- [ ] Decide metrics sink: k6 JSON summary to start, Prometheus/Grafana later.

## Separate track: PhpBench micro-benchmark of the Redis fake workload

In addition to the k6 / HTTP path above, we want to run the **same captured
Redis fake workload under [PhpBench](https://phpbench.readthedocs.io)**, as a
standalone, single-process micro-benchmark. This gives us tight statistical
rigor (revs/iterations, mean/mode/stdev, retry until stable) on the *pure*
replay cost — no HTTP, nginx or FPM in the path — which complements the k6
capability/concurrency numbers.

- [ ] Add a PhpBench `*Bench.php` subject that loads a trace and replays its
      Redis commands via the production client (reuse the same replay code as
      the endpoint so both paths exercise identical logic).
- [ ] Parameterize over the corpus (`@ParamProviders`) so each trace is its own
      benchmarked subject; report per-trace and aggregate.
- [ ] Pin revs/iterations/warmup; commit a `phpbench.json` config.
- [ ] Compare clients/backends (Relay / PhpRedis / Predis) as separate runs.
- [ ] Keep this isolated-Redis benchmark distinct from the k6 end-to-end one —
      they answer different questions (raw call cost vs system capability).

## Open decisions

- [ ] Corpus multiplicity: 100 unique traces × 100 repeats = 10,000 (current
      default). Confirm or adjust per real capture.
- [ ] CPU contention: `sleep` yields the CPU (conservative, isolates Redis).
      Switch to a calibrated busy-loop only if PHP CPU cost must be modeled.
- [ ] Key namespacing: replay captured keys verbatim (realistic shared-cache
      contention) vs per-worker namespacing. Default: verbatim.
