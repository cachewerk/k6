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
# Capture baseline corpus (no prefetch, single alloptions GET)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-1 --env OCP_TOKEN=…

# Capture hash-alloptions corpus
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-2 --env OCP_TOKEN=…

# Capture prefetch corpus (warm the site with a plain run first?)
k6 run har-replay.js --env SITE_URL=https://example.com --env PROFILE=capture-3 --env OCP_TOKEN=…
```

See [HAR capture profiles](#har-capture-1-3) below.
