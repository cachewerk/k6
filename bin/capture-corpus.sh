#!/usr/bin/env bash
#
# Capture replay corpora by driving the recorded HAR chain (har-replay.js) under
# each capture profile, then assembling corpus + seed with the example .CAPTURE
# tools. One recorded HAR (the request chain) → three corpora:
#
#   capture-baseline   plain string keys   GET / SET
#   capture-hfe        groups-as-hashes    HGET / HSET   (group_flush=atomic)
#   capture-prefetch   prefetched / warm
#
# The profile's X-OCP-* headers reconfigure OCP per request (via .BENCHMARK/
# headers.php); X-K6-Capture (added to each capture profile) arms the capture
# mu-plugin. Per profile this captures COLD (cache reset first → carries the
# values, so it seeds replay) then WARM (the steady-state workload), producing:
#
#   <CAPTURE_DIR>/corpus/<profile>/{cold,warm}/   + <profile>/seed.json
#
# Requires k6 + php + the running WordPress site. The reset step flushes Redis.
#
# Usage:
#   ./capture-corpus.sh                 # all three capture profiles
#   ./capture-corpus.sh capture-hfe     # just one
#
# Env overrides:
#   SITE_URL     default https://example.test
#   K6_SECRET    default k6-benchmark-secret   (must match wp-config-benchmark.php)
#   CAPTURE_DIR  default $HOME/Development/Sites/example/.CAPTURE
#   HAR_FILE     path to the recorded HAR to replay (required)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SITE_URL="${SITE_URL:-https://example.test}"
K6_SECRET="${K6_SECRET:-k6-benchmark-secret}"
CAPTURE_DIR="${CAPTURE_DIR:-$HOME/Development/Sites/example/.CAPTURE}"

HAR_SCRIPT="$HERE/../har-replay.js"
HAR_FILE="${HAR_FILE:-}"
BUILD_CORPUS="$CAPTURE_DIR/bin/build-corpus.php"
BUILD_SEED="$CAPTURE_DIR/bin/build-seed.php"
RAW_DIR="$CAPTURE_DIR/corpus/raw"
CORPUS_DIR="$CAPTURE_DIR/corpus"

DEFAULT_PROFILES=(capture-baseline capture-hfe capture-prefetch)

CURL=(curl -sk --max-time 60)   # -k: Valet serves a self-signed cert

log() { printf '%s\n' "$*"; }

preflight() {
    command -v k6  >/dev/null || { log "k6 not installed"; exit 1; }
    command -v php >/dev/null || { log "php not installed"; exit 1; }
    [ -f "$HAR_SCRIPT" ]   || { log "missing $HAR_SCRIPT"; exit 1; }
    { [ -n "$HAR_FILE" ] && [ -f "$HAR_FILE" ]; } || { log "set HAR_FILE=/path/to/recording.har (the HAR to replay)"; exit 1; }
    [ -f "$BUILD_CORPUS" ] || { log "missing $BUILD_CORPUS — set CAPTURE_DIR"; exit 1; }
    [ -f "$BUILD_SEED" ]   || { log "missing $BUILD_SEED — set CAPTURE_DIR"; exit 1; }
    mkdir -p "$RAW_DIR"

    local code
    code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$SITE_URL/" || echo 000)
    [[ "$code" =~ ^[23] ]] || { log "$SITE_URL unreachable (HTTP $code)"; exit 1; }
    log "site: $SITE_URL"
    log "capture dir: $CAPTURE_DIR"
}

# Cold start: flush the object cache + transients + WC sessions.
reset_cache() {
    local code
    code=$("${CURL[@]}" -X POST -H "X-K6-Secret: $K6_SECRET" \
        -o /dev/null -w '%{http_code}' "$SITE_URL/wp-json/k6/v1/reset")
    [ "$code" = "200" ] || { log "  reset failed (HTTP $code) — check K6_SECRET"; exit 1; }
    log "  cache reset"
}

# Drive the HAR chain once (vus:1, iterations:1) under a profile, capturing.
drive() {
    local profile="$1"
    rm -f "$RAW_DIR"/*.json "$RAW_DIR"/.*.tmp 2>/dev/null || true
    k6 run --quiet --insecure-skip-tls-verify \
        --env HAR="$HAR_FILE" --env SITE_URL="$SITE_URL" --env PROFILE="$profile" "$HAR_SCRIPT"
}

capture_profile() {
    local profile="$1"
    log "== $profile =="
    reset_cache

    log "  [cold] drive + capture (carries values -> seed)"
    drive "$profile"
    php "$BUILD_CORPUS" --raw="$RAW_DIR" --out="$CORPUS_DIR/$profile/cold"
    php "$BUILD_SEED" --corpus="$CORPUS_DIR/$profile/cold" --out="$CORPUS_DIR/$profile/seed.json"

    log "  [warm] drive + capture (steady-state workload)"
    drive "$profile"
    php "$BUILD_CORPUS" --raw="$RAW_DIR" --out="$CORPUS_DIR/$profile/warm"
}

main() {
    preflight

    local profiles=("$@")
    [ "${#profiles[@]}" -eq 0 ] && profiles=("${DEFAULT_PROFILES[@]}")

    for profile in "${profiles[@]}"; do
        capture_profile "$profile"
    done

    log ""
    log "Done. Per profile: $CORPUS_DIR/<profile>/{cold,warm}/ + seed.json"
}

main "$@"
