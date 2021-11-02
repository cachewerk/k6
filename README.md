# k6

We use these script to benchmark Object Cache Pro and Relay with various hosting partners.

## Setup

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/), or use [k6 Cloud](https://k6.io/cloud/).

## Run

```
k6 run wp.js

k6 run woo-checkout.js
```

## Environment variables

### Site URL

You can pass in the `SITE_URL` to point the traffic at your own site.

```
k6 run -e SITE_URL=http://localhost:8080 woo-ramping.js
```

### Bypass page caches

To attempt bypassing page caches without logging in, pass in `BYPASS_CACHE`:

```
k6 run -e BYPASS_CACHE=1 wp.js
```

## Reset WooCommerce orders

```
wp post delete --force $(wp post list --post_type=shop_order --format=ids --posts_per_page=-1)
wp cache flush
```

## Seeding users

For test runs with logged in users, use this seed command:

```
for USR_NO in {1..10000}; do wp user create "test${USR_NO}" "test${USR_NO}@example.com" --role=subscriber --user_pass=3405691582; done;
```
