# k6

We use these scripts to benchmark Object Cache Pro and Relay with various hosting partners.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/), or use [k6 Cloud](https://k6.io/cloud/).

## Tests

### `wp.js`

Fetches all WordPress sitemaps and requests random URLs.

```
k6 run wp.js --env SITE_URL=https://example.com
k6 run wp.js --vus=100 --duration=10m --env SITE_URL=https://example.com
```

### `woo-checkout.js`

Loads the homepage, selects and loads a random category, selects a random product and adds it to the cart, loads the cart page and then places an order.

```
wp option update woocommerce_enable_guest_checkout no --autoload=no
wp option update woocommerce_enable_signup_and_login_from_checkout yes --autoload=no

k6 run woo-checkout.js --env SITE_URL=https://example.com
```

Be sure to [reset WooCommerce](#reset-woocommerce) between test runs.

### `woo-customer.js`

Loads the homepage, signs in, views at orders and then their account details.

```
k6 run woo-customer.js --env SITE_URL=https://example.com
```

This script requires [seeded users](#seeding-users).

## Cloud tests

Using `k6 run` will execute tests locally. To run _k6 Cloud_ tests from the CLI, use `k6 cloud` instead. You can even set the "Project ID" using the `PROJECT_ID` environment variable.

```
k6 cloud wp.js --env BYPASS_CACHE=1 --env PROJECT_ID=123456 --env SITE_URL=https://example.com
```

## Custom metrics

- TODO: what is this, how to get it working, and explain all metrics...
- TODO: add Redis/Relay metrics...
- TODO: how to set default comparison of custom metrics?
- TODO: ensure metrics (like Redis ops/sec) is optional

## Environment variables

### Site URL

You can pass in the `SITE_URL` to point the traffic at at specific URL.

```
k6 run wp.js --env SITE_URL=http://localhost:8080
```

### Bypass page caches

To attempt bypassing page caches without logging in, pass in `BYPASS_CACHE`:

```
k6 run wp.js --env BYPASS_CACHE=1
```

## Reset WooCommerce

```
wp post delete --force $(wp post list --post_type=shop_order --format=ids --posts_per_page=-1)
wp user delete --yes $(wp user list --role=customer --format=ids --posts_per_page=-1)
wp cache flush
```

## Seeding users

Load tests that run with logged in users, use this seed command to create 100 users:

```
for USR_NO in {1..100}; do wp user create "test${USR_NO}" "test${USR_NO}@example.com" --role=subscriber --user_pass=3405691582; done;
```
