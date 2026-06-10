# Request capture

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
# Capture baseline corpus (plain keys, single alloptions GET, no prefetch)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-baseline

# Capture HFE corpus (group_flush=atomic → groups-as-hashes, GET→HGET)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-hfe

# Capture prefetch corpus (warm the site with a plain run first, then capture)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-prefetch
```

See [Available profiles](README.md#available-profiles) for the full list.
