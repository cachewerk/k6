# k6

Loads the homepage, signs in, views orders and then account details.

```bash
k6 run k6-woo-customer.js --env SITE_URL=https://example.com
```

This script requires [seeded users](#seeding-users).

## Seeding users

Load tests that run with logged in users require 100 seeded users:

```
for USR_NO in {1..100}; do wp user create "test${USR_NO}" "test${USR_NO}@example.com" --role=subscriber --user_pass=3405691582; done;
```
