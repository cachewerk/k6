# k6

We use these scripts to benchmark Object Cache Pro and Relay with various hosting partners.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/), or use [k6 Cloud](https://k6.io/cloud/).

## Tests

### `wp.js`

Fetches all WordPress sitemaps and requests random URLs.

```
k6 run wp.js
k6 run wp.js --vus=100 --duration=10m
```

### `woo-checkout.js`

Loads the homepage, selects and loads a random category, selects a random product and adds it to the cart, loads the cart page and then places an order.

```
wp option update woocommerce_enable_guest_checkout no --autoload=no
wp option update woocommerce_enable_signup_and_login_from_checkout yes --autoload=no

k6 run woo-checkout.js
```

Be sure to [reset WooCommerce](#reset-woocommerce) between test runs.

### `woo-customer.js`

Loads the homepage, signs in, views at orders and then their account details.

```
k6 run woo-customer.js
```

This script requires [seeded users](#seeding-users).

## Environment variables

### Site URL

You can pass in the `SITE_URL` to point the traffic at your own site.

```
k6 run wp.js -e SITE_URL=http://localhost:8080
```

### Bypass page caches

To attempt bypassing page caches without logging in, pass in `BYPASS_CACHE`:

```
k6 run wp.js -e BYPASS_CACHE=1 
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
