# Performance screen

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
