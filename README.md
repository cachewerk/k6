# k6

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/).

```
k6 run wp.js

k6 run woo-checkout.js
```

## Reset WooCommerce orders

```
wp post delete --force $(wp post list --post_type=shop_order --format=ids --posts_per_page=-1)
wp cache flush
```

## Environment variables

You can pass in the `SITE_URL`:

```
k6 run -e SITE_URL=http://localhost:8080 woo-ramping.js
```
