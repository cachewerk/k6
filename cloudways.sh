#!/usr/bin/env bash

set -eo pipefail

vus=100
duration=10m

if [[ -n "$1" ]]; then
    base_dir="$1"
else
    base_dir="runs/${vus}vus/${duration}"
fi

out_dir="$base_dir"

echo "Making directory '$out_dir'"
mkdir -p "$out_dir"

for n in {1..10}; do
    echo "[$(date +%Y-%m-%dT%H:%M:%S)] Running iteration $n"

    ssh cloudways 'php /var/www/html/public_html/relay/flush.php'

    k6 run wp.js \
        --duration="$duration" \
        --vus="$vus" \
        -e SITE_URL=https://app17262.cloudwayssites.com \
        -e BYPASS_CACHE=1 \
        --summary-export="${out_dir}/$(date +%s).json"
done
