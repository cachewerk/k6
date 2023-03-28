#!/bin/bash

set -e

# Running tests:
#  k6 run wp.js --summary-export example_com-ocp+relay.json --env BYPASS_CACHE=1 --env SITE_URL=https://example.com

WORKDIR="${1:-.}"

CALCULATIONS=(
    "avg"
    # "p(90)"
    "p(95)"
    # "min"
    # "max"
    # "med"
)

# https://k6.io/docs/using-k6/metrics/
METRICS=(
    "response_cached"
    "errors"
    "vus"
    # "vus_max"
    # "iteration_duration"
    "iterations"
    # "http_req_blocked"
    # "http_req_connecting"
    "http_req_duration"
    "http_req_duration{expected_response:true}"
    "http_req_waiting"
    "http_req_failed"
    # "http_req_receiving"
    # "http_req_sending"
    # "http_req_tls_handshaking"
    "http_reqs"
    # "data_received"
    # "data_sent"
    "x_using_phpredis"
    "x_using_relay"
    "redis_ops_per_sec"
    "redis_hit_ratio"
    # "redis_hits"
    # "redis_keys"
    # "redis_memory_fragmentation_ratio"

    "relay_ops_per_sec"
    "relay_hit_ratio"
    # "relay_keys"
    # "relay_memory_active"
    "relay_memory_ratio"

    "wp_hits"
    "wp_hit_ratio"
    "wp_ms_total"
    "wp_ms_cache"
    "wp_ms_cache_avg"
    "wp_ms_cache_ratio"
    "wp_prefetches"
    "wp_sql_queries"
    "wp_store_reads"
    "wp_store_writes"
)

BENCHMARKS=$(find $WORKDIR -name "*.json" -print)
BENCHMARKS_ARRAY=($BENCHMARKS)

>&2 echo "Reading ${#BENCHMARKS_ARRAY[@]} benchmarks..."

HEADER=""

HEADER+='"Metric",'
for file in $BENCHMARKS; do
  NAME=$(basename $file .json)
  HEADER+="\"$NAME\","
done

echo "$HEADER"

for metric in "${METRICS[@]}"; do
  COUNTER=$(jq ".metrics.\"$metric\".rate" $file)
  RATE=$(jq ".metrics.\"$metric\".fails" $file)
  GAUGE=$(jq ".metrics.\"$metric\".value" $file)

  if [[ "$COUNTER" != "null" ]]; then

    ROW="\"$metric.count\","

    for file in $BENCHMARKS; do
      VALUE=$(jq ".metrics.\"$metric\".count" $file)
      ROW+="\"$VALUE\","
    done

    echo "$ROW"

    ROW="\"$metric.rate/sec\","

    for file in $BENCHMARKS; do
      VALUE=$(jq ".metrics.\"$metric\".rate" $file)
      ROW+=$(printf "\"%.2f\"," $VALUE)
    done

    echo "$ROW"

  elif [[ "$RATE" != "null" ]]; then

    ROW="\"$metric\","

    for file in $BENCHMARKS; do
      VALUE=$(jq ".metrics.\"$metric\".value" $file)
      VALUE=$((VALUE * 100))
      ROW+="\"$VALUE%\","
    done

    echo "$ROW"

  elif [[ "$GAUGE" != "null" ]]; then

    ROW="\"$metric\","

    for file in $BENCHMARKS; do
      VALUE=$(jq ".metrics.\"$metric\".value" $file)
      ROW+="\"$VALUE\","
    done

    echo "$ROW"

  else

    for calculation in "${CALCULATIONS[@]}"; do
      ROW="\"$metric.$calculation\","

      for file in $BENCHMARKS; do
        VALUE=$(jq ".metrics.\"$metric\".\"$calculation\" | select(. != null)" $file)
        ROW+="\"$VALUE\","
      done

      echo "$ROW"
    done

  fi
done

>&2 echo "Done"
