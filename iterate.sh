#!/usr/bin/env bash

echoExec() {
    echo "+ $*"
    "$@"
}

checkClient() {
  if ! server-config/check-client --client "$1" "http://hydra.test"; then
    echo "Client $client is not ready. Exiting."
    exit 1
  fi
}

set -euo pipefail

# Default values
CLIENT="relay"
DEFAULT=4
VUS=""
WORKERS=""
DURATION="10m"
OUTDIR=""
RESUME=false
ACCESS=sync
LIMIT=50
DYNAMIC=

# Parse arguments
while [[ $# -gt 0 ]]; do
  case "$1" in
    --client)
      CLIENT="$2"
      shift 2
      ;;
    --access)
      ACCESS="$2"
      shift 2
      ;;
    --duration)
      DURATION="$2"
      shift 2
      ;;
    --output-dir)
      OUTDIR="$2"
      shift 2
      ;;
    --resume)
      RESUME=true
      shift
      ;;
    --vus)
      VUS="$2"
      shift 2
      ;;
    --workers)
      WORKERS="$2"
      shift 2
      ;;
    --limit)
      LIMIT="$2"
      shift 2
      ;;
    --dynamic)
      DYNAMIC=true
      shift
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

# Client must be 'relay' or 'phpredis' or 'both'
if [[ "$CLIENT" != 'both' && "$CLIENT" != "phpredis" && "$CLIENT" != "relay" ]]; then
  echo "Invalid --client: $CLIENT. Must be 'both', 'phpredis' or 'relay'." >&2
  exit 1
fi

if [[ "$CLIENT" == "both" ]]; then
    CLIENTS=("phpredis" "relay")
else
    CLIENTS=("$CLIENT")
fi

# Logic for defaulting VUS and WORKERS
if [[ -z "$VUS" && -z "$WORKERS" ]]; then
  VUS=$DEFAULT
  WORKERS=$DEFAULT
elif [[ -z "$VUS" ]]; then
  VUS=$WORKERS
elif [[ -z "$WORKERS" ]]; then
  WORKERS=$VUS
fi

case "$ACCESS" in
  sync|staggered|random) ;;
  *) echo "Invalid --access: $ACCESS" >&2; exit 1 ;;
esac

# Setup output directory if not specified
if [[ -z "$OUTDIR" ]]; then
  printf -v OUTDIR "%02dfpm-02%dvus" "$WORKERS" "$VUS"
fi
mkdir -p "$OUTDIR"

SITE_URL="http://hydra.test"
EVENTS_VALUES=(10 20)
RATIO_VALUES=(-1 0.01 1 1.50)
RELAY_DBS=(1 4)
INVALIDATIONS=(0 1)

if [[ "$CLIENT" == "relay" || "$CLIENT" == "both" ]]; then
    RELAY_COMBINATIONS=$(( ${#EVENTS_VALUES[@]} * ${#RATIO_VALUES[@]} * ${#RELAY_DBS[@]} * ${#INVALIDATIONS[@]} ))
else
  RELAY_COMBINATIONS=1
fi

if [[ "$CLIENT" == "both" ]]; then
  TOTAL_STEPS=$(( RELAY_COMBINATIONS + 1 ))
else
  TOTAL_STEPS=$RELAY_COMBINATIONS
fi

ON_STEP=1

trap 'echo "Interrupted. Partial results in: $OUTDIR"; exit 1' INT
SECONDS=0

for client in "${CLIENTS[@]}"; do
  if [[ "$client" == "relay" ]]; then
    events_list=("${EVENTS_VALUES[@]}")
    ratio_list=("${RATIO_VALUES[@]}")
    db_list=("${RELAY_DBS[@]}")
  else
    events_list=("${EVENTS_VALUES[0]}")
    ratio_list=("${RATIO_VALUES[0]}")
    db_list=("${RELAY_DBS[0]}")
  fi

  for events in "${events_list[@]}"; do
    for ratio in "${ratio_list[@]}"; do
      for db in "${db_list[@]}"; do
        for inv in "${INVALIDATIONS[@]}"; do
          echoExec server-config/set-values \
              --client "$client" \
              --events "$events" \
              --ratio "$ratio" \
              --invalidations "$inv" \
              --dbs "$db"

          checkClient "$client"

          echoExec ssh hydra \
              '/home/mike/dev/phpfarm/src/php-8.2.28/cycle-fpm-pool'

          printf -v fname "%s/%s-e%+03d_r%+06.2f_d%d_w%02d_u%02d.json" \
            "$OUTDIR" "$client" "$events" "$ratio" "$db" "$WORKERS" "$VUS"

          if $RESUME && [[ -f "$fname" ]]; then
            echo "[SKIP] Already exists: $fname"
            ((ON_STEP++))
            continue
          fi

          n=1
          while [[ -f "$fname" ]]; do
            echo "[DUPLICATE] Already exists: $fname"
            fname="${fname%.json}.$n.json"
            ((n++))
          done

          printf "[%03d / %03d] " "$ON_STEP" "$TOTAL_STEPS"
          echo "  Events: $events, Ratio: $ratio, DB: $db"
          echo "→ Output file: $fname"

          echo "DBS: $db"

          echoExec k6 run wp.js \
              --duration="$DURATION" \
              --vus "$VUS" \
              -e BYPASS_CACHE=1 \
              -e LIMIT="$LIMIT" \
              -e ACCESS_MODE="$ACCESS" \
              -e SITE_URL="$SITE_URL" \
              --insecure-skip-tls-verify \
              --summary-export="$fname"

          ((ON_STEP++))
        done
      done
    done
  done
done

echo "All tests complete. Results in: $OUTDIR"
printf "Total elapsed time: %dm%ds\n" $((SECONDS/60)) $((SECONDS%60))
