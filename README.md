# k6

Scripts to benchmark Object Cache Pro and Relay against various plugins.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/). All tests can be run locally using `k6 run` or in the cloud using `k6 cloud`. 

## Metrics

When Object Cache Pro is installed, custom metrics for [WordPress, Redis and Relay](lib/metrics.js) are automatically collected. For other plugins, use the [`k6-metrics.php`](./stubs/k6-metrics.php) as a must-use plugin to capture more metrics.

## Tests

### `wp.js`

Fetches all WordPress sitemaps and requests random URLs.

```bash
k6 run wp.js --env SITE_URL=https://example.com
k6 run wp.js --vus=100 --duration=10m --env SITE_URL=https://example.com
```

### `woo-checkout.js`

Loads the homepage, selects and loads a random category, selects a random product and adds it to the cart, loads the cart page and then places an order.

```bash
wp option update woocommerce_enable_guest_checkout no --autoload=no
wp option update woocommerce_enable_signup_and_login_from_checkout yes --autoload=no

k6 run woo-checkout.js --env SITE_URL=https://example.com
```

Be sure to [reset WooCommerce](#reset-woocommerce) between test runs.

### `woo-customer.js`

Loads the homepage, signs in, views orders and then account details.

```bash
k6 run woo-customer.js --env SITE_URL=https://example.com
```

This script requires [seeded users](#seeding-users).

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `SITE_URL` | Yes | Base URL of the site, without trailing slash |
| `SITEMAP_URL` | No | Custom sitemap URL (default: `{SITE_URL}/wp-sitemap.xml`). `wp.js` only. |
| `BYPASS_CACHE` | No | When set, sends cookies that bypass full-page caches |
| `PROFILE` | No | Named benchmark profile (see [Profiles](#profiles)). Omit to use the site's default configuration. |
| `OCP_TOKEN` | No | Object Cache Pro license token, passed as `X-OCP-Token`. Required when using an OCP profile. |
| `PROJECT_ID` | No | k6 Cloud project ID |

## Profiles

Profiles select which object cache drop-in and client to use for a run, applied via HTTP request headers. Omitting `PROFILE` uses the site's PHP configuration unchanged.

```bash
k6 run wp.js --env SITE_URL=https://example.com --env PROFILE=ocp-relay --env OCP_TOKEN=abc123
```

### Available profiles

| Profile | Drop-in | Client |
|---|---|---|
| `ocp-relay` | Object Cache Pro | Relay |
| `ocp-phpredis` | Object Cache Pro | PhpRedis |
| `ocp-predis` | Object Cache Pro | Predis |
| `roc-phpredis` | Redis Object Cache | PhpRedis |
| `roc-relay` | Redis Object Cache | Relay |
| `roc-predis` | Redis Object Cache | Predis |
| `none` | WordPress built-in memory cache | — |

Profiles are defined in [`lib/profiles.js`](lib/profiles.js). Additional OCP options (`X-OCP-Compression`, `X-OCP-Serializer`, etc.) can be set by adding new profiles or extending existing ones. See `__data/README.md` for all supported headers.

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
