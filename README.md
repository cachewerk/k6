# k6

Scripts to benchmark Object Cache Pro and Relay against various plugins.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/). All tests can be run locally using `k6 run` or in the cloud using `k6 cloud`. 

## Metrics

When Object Cache Pro is installed, custom metrics for [WordPress, Redis and Relay](lib/metrics.js) are automatically collected. For other plugins, use the [`k6-metrics.php`](./stubs/mu-plugin.php) as a must-use plugin to capture more metrics.

## Tests

### `wp.js`

Fetches all WordPress sitemaps and requests random URLs.

```bash
k6 run wp.js --env SITE_URL=https://example.com --env BYPASS_CACHE=1
k6 run wp.js --vus=100 --duration=10m --env SITE_URL=https://example.com --env BYPASS_CACHE=1
```

### `woo-checkout.js`

Loads the homepage, selects and loads a random category, selects a random product and adds it to the cart, loads the cart page and then places an order.

```bash
wp option update woocommerce_enable_guest_checkout no --autoload=no
wp option update woocommerce_enable_signup_and_login_from_checkout yes --autoload=no

k6 run woo-checkout.js --env SITE_URL=https://example.com --env BYPASS_CACHE=1
```

Be sure to [reset WooCommerce](#reset-woocommerce) between test runs.

### `woo-customer.js`

Loads the homepage, signs in, views at orders and then their account details.

```bash
k6 run woo-customer.js --env SITE_URL=https://example.com --env BYPASS_CACHE=1
```

This script requires [seeded users](#seeding-users).

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

## Environment variables

### Project ID

You can set the k6 Cloud "Project ID" using the `PROJECT_ID` environment variable.

```bash
k6 cloud wp.js --env PROJECT_ID=123456 --env SITE_URL=https://example.com
```
