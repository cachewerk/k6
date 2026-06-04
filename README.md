# k6

Scripts to benchmark Object Cache Pro and Relay against various plugins.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/). All tests can be run locally using `k6 run` or in the cloud using `k6 cloud`. 

## Metrics

When Object Cache Pro is installed, custom metrics for [WordPress, Redis and Relay](lib/metrics.js) are automatically collected. For other plugins, use the [`k6-metrics.php`](./stubs/k6-metrics.php) as a must-use plugin to capture more metrics.

## Tests

### `k6-wp.js`

Fetches all WordPress sitemaps and iterates through URLs sequentially for reproducible runs.

```bash
k6 run k6-wp.js --env SITE_URL=https://example.com
k6 run k6-wp.js --vus=100 --duration=10m --env SITE_URL=https://example.com
```

| Variable | Required | Description |
| `SITEMAP_URL` | No | Custom sitemap URL (default: `{SITE_URL}/wp-sitemap.xml`). |
| `PROFILE` | No | Named benchmark profile (see [Profiles](#profiles)). Omit to use the site's default configuration. |

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `SITE_URL` | Yes | Base URL of the site, without trailing slash |
| `BYPASS_CACHE` | No | When set, sends cookies that bypass full-page caches |
| `PROJECT_ID` | No | k6 Cloud project ID |
| `OCP_TOKEN` | No | Object Cache Pro license token, passed as `X-OCP-Token`. Required when using an OCP profile. |
| `K6_SECRET` | No | Secret token for the reset endpoint. When set, `setup()` flushes the object cache, transients, and WooCommerce sessions before the run. Must match `K6_SECRET` in `wp-config-benchmark.php`. |

## Profiles

Profiles select which object cache drop-in and client to use for a run, applied via HTTP request headers. Omitting `PROFILE` uses the site's PHP configuration unchanged.

```bash
k6 run k6-wp.js --env SITE_URL=https://example.com --env PROFILE=ocp-relay --env OCP_TOKEN=abc123
```

### Available profiles

**Base**

| Profile | Drop-in | Client |
|---|---|---|
| `none` | WordPress built-in memory cache | — |
| `ocp-relay` | Object Cache Pro | Relay |
| `ocp-phpredis` | Object Cache Pro | PhpRedis |
| `roc-phpredis` | Redis Object Cache | PhpRedis |
| `roc-relay` | Redis Object Cache | Relay |

**HAR capture (1–3)** — use with `har-replay.js` to drive the site for corpus capture. Each profile isolates one variable that changes the Redis command stream. Only read-only (frontend) URLs; `group_flush` is a no-op here so `scan` is used throughout.

| Profile | `prefetch` | `split_alloptions` | What it captures |
|---|---|---|---|
| `capture-1` | false | false | Baseline — single alloptions `GET`, no prefetch |
| `capture-2` | false | true | Hash alloptions — lazy `HGET`/`HMGET`, very different command count |
| `capture-3` | true | false | Prefetch — batched key preload at request start (warm the site first) |

**Corpus capture (A–H)** — use with `stubs/k6-capture.php` to capture Redis command traces. Vary `prefetch`, `split_alloptions`, and `group_flush`.

| Profile | `prefetch` | `split_alloptions` | `group_flush` |
|---|---|---|---|
| `corpus-a` | false | false | scan |
| `corpus-b` | false | false | atomic |
| `corpus-c` | false | true | scan |
| `corpus-d` | false | true | atomic |
| `corpus-e` | true | false | scan |
| `corpus-f` | true | false | atomic |
| `corpus-g` | true | true | scan |
| `corpus-h` | true | true | atomic |

**Replay configs (R0–R7)** — vary `serializer` and `compression` for replay runs. `relay.adaptive` is not a request header; set `REPLAY_RELAY_ADAPTIVE=on/off` separately for odd-numbered configs.

| Profile | `serializer` | `compression` | `relay.adaptive` |
|---|---|---|---|
| `replay-r0` | php | none | off |
| `replay-r1` | php | none | on |
| `replay-r2` | php | lz4 | off |
| `replay-r3` | php | lz4 | on |
| `replay-r4` | igbinary | none | off |
| `replay-r5` | igbinary | none | on |
| `replay-r6` | igbinary | lz4 | off |
| `replay-r7` | igbinary | lz4 | on |

Profiles are defined in [`lib/profiles.js`](lib/profiles.js). See `__data/README.md` for all supported headers.
