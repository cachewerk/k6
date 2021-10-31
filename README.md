# k6

Make sure [k6 is installed](https://k6.io/docs/getting-started/installation/).

```bash
k6 run wp.js
k6 run woo-ramping.js
k6 run woo-constant.js
```

## Environment variables

You can pass in the `SITE_URL`:

```bash
k6 run -e SITE_URL=http://localhost:8080 woo-ramping.js
```
