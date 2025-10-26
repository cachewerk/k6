#!/bin/bash

set -e

if [[ -z "$1" ]]; then
    echo "Usage: $0 /path/to/results > export.csv"
    exit 1
fi

workdir="${1:-.}"

calculations=(
    "avg"
    # "p(90)"
    "p(95)"
    # "min"
    # "max"
    # "med"
)

# See https://k6.io/docs/using-k6/metrics/
metrics=(
    "response_cached"
    "errors"
    # "vus"
    "vus_max"
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
    "x_using_predis"
    "x_using_apcu"
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
    "wp_sys_load"
    "wp_store_reads"
    "wp_store_writes"
)

benchmarks=$(find "$workdir" -name "*.json" -print)
benchmarks_array=($benchmarks)

>&2 echo "Reading ${#benchmarks_array[@]} benchmarks..."

header='"Metric",'
for file in $benchmarks; do
    name=$(basename "$file" .json)
    header+="\"$name\","
done
echo "$header"

for metric in "${metrics[@]}"; do
    counter=$(jq ".metrics.\"$metric\".rate" "$file")
    rate=$(jq ".metrics.\"$metric\".fails" "$file")
    gauge=$(jq ".metrics.\"$metric\".value" "$file")

    if [[ "$counter" != "null" ]]; then
        row="\"$metric.count\","
        for file in $benchmarks; do
            value=$(jq ".metrics.\"$metric\".count" "$file")
            row+="\"$value\","
        done
        echo "$row"

        row="\"$metric.rate/sec\","
        for file in $benchmarks; do
            value=$(jq ".metrics.\"$metric\".rate" "$file")
            row+=$(printf "\"%.2f\"," "$value")
        done
        echo "$row"
    elif [[ "$rate" != "null" ]]; then
        row="\"$metric\","
        for file in $benchmarks; do
            value=$(jq ".metrics.\"$metric\".value" "$file")
            percent=$(echo "scale=2; $value * 100" | bc)
            row+="\"$percent%\","
        done
        echo "$row"
    elif [[ "$gauge" != "null" ]]; then
        row="\"$metric\","
        for file in $benchmarks; do
            value=$(jq ".metrics.\"$metric\".value" "$file")
            row+="\"$value\","
        done
        echo "$row"
    else
        for calculation in "${calculations[@]}"; do
            row="\"$metric.$calculation\","
            for file in $benchmarks; do
                value=$(jq ".metrics.\"$metric\".\"$calculation\" | select(. != null)" "$file")
                row+="\"$value\","
            done
            echo "$row"
        done
    fi
done

>&2 echo "Done"
