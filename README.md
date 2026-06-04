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

### `k6-screen.js`

Runs multiple profiles sequentially in a single k6 invocation and produces a unified summary with metrics broken out per profile — useful for quickly ranking configurations before committing to full benchmark runs.

Each profile runs `VUS × ITERATIONS` requests. Defaults to all profiles if `PROFILES` is omitted. Resets the site cache before the run if `K6_SECRET` is set.

```bash
# Screen all profiles (500 requests each, 10 VUs × 50 iterations)
K6_SECRET=secret SITE_URL=https://example.com k6 run k6-screen.js

# Screen a subset
K6_SECRET=secret SITE_URL=https://example.com \
    k6 run k6-screen.js -e PROFILES=ocp-relay,ocp-phpredis,roc-relay,none

# More requests, more VUs
K6_SECRET=secret SITE_URL=https://example.com \
    k6 run k6-screen.js -e PROFILES=ocp-relay,ocp-phpredis -e ITERATIONS=100 -e VUS=20
```

| Variable | Default | Description |
|---|---|---|
| `PROFILES`   | all profiles | Comma-separated list of profile names to screen |
| `ITERATIONS` | `50`         | Iterations each VU runs per profile |
| `VUS`        | `10`         | Virtual users per profile |
| `TIMEOUT`    | `120`        | Max seconds per profile before force-stop. Also controls the gap between profiles — increase if a slow profile (e.g. `none`) needs more time to complete its iterations. |

### `har-replay.js`

Replays a sequence of requests captured from Chrome DevTools as a HAR file, sequentially with a single virtual user. Use this to drive the site for corpus capture or to reproduce a specific browsing flow without load.

**Capture workflow:**

1. Open Chrome DevTools → Network tab, check **Preserve log**, perform the flow
2. Right-click any request → **Save all as HAR with content**
3. Convert: `k6 convert recording.har -O k6/har-replay.js`
4. Restore `vus: 1, iterations: 1` in `options` (the converter resets these)
5. Pass `params` to every `http.get()` / `http.post()` in the generated body

**Running a capture:**

```bash
# Capture baseline corpus (no prefetch, single alloptions GET)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-1 --env OCP_TOKEN=…

# Capture hash-alloptions corpus
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-2 --env OCP_TOKEN=…

# Capture prefetch corpus — warm the site with a plain run first
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-3 --env OCP_TOKEN=…
```

See [HAR capture profiles](#har-capture-1-3) below.

### `k6-woo-checkout.js`

Loads the homepage, selects and loads a random category, selects a random product and adds it to the cart, loads the cart page and then places an order.

```bash
wp option update woocommerce_enable_guest_checkout no --autoload=no
wp option update woocommerce_enable_signup_and_login_from_checkout yes --autoload=no

k6 run k6-woo-checkout.js --env SITE_URL=https://example.com
```

Be sure to [reset WooCommerce](#reset-woocommerce) between test runs.

### `k6-woo-customer.js`

Loads the homepage, signs in, views orders and then account details.

```bash
k6 run k6-woo-customer.js --env SITE_URL=https://example.com
```

This script requires [seeded users](#seeding-users).

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `SITE_URL` | Yes | Base URL of the site, without trailing slash |
| `SITEMAP_URL` | No | Custom sitemap URL (default: `{SITE_URL}/wp-sitemap.xml`). `k6-wp.js` only. |
| `BYPASS_CACHE` | No | When set, sends cookies that bypass full-page caches |
| `PROFILE` | No | Named benchmark profile (see [Profiles](#profiles)). Omit to use the site's default configuration. `k6-wp.js` only. |
| `PROFILES` | No | Comma-separated list of profiles to screen. Defaults to all. `k6-screen.js` only. |
| `DURATION` | No | Seconds per profile (default: `30`). `k6-screen.js` only. |
| `VUS` | No | Virtual users per profile (default: `10`). `k6-screen.js` only. |
| `K6_SECRET` | No | Secret token for the reset endpoint. When set, `setup()` flushes the object cache, transients, and WooCommerce sessions before the run. Must match `K6_SECRET` in `wp-config-benchmark.php`. |
| `OCP_TOKEN` | No | Object Cache Pro license token, passed as `X-OCP-Token`. Required when using an OCP profile. |
| `PROJECT_ID` | No | k6 Cloud project ID |

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

## Reset WooCommerce

```
wp post delete --force $(wp post list --post_type=shop_order --format=ids --posts_per_page=-1)
wp user delete --yes $(wp user list --role=customer --format=ids --posts_per_page=-1)
wp cache flush
```

## Seeding users

Load tests that run with logged in users require 100 seeded users:

```
for USR_NO in {1..100}; do wp user create "test${USR_NO}" "test${USR_NO}@example.com" --role=subscriber --user_pass=3405691582; done;
```
