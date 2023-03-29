#!/bin/bash

set -e

# Check if a sitemap URL is provided
if [[ -z "$1" ]]; then
    echo "Usage: $0 <https://example.com/wp-sitemap.xml>"
    exit 1
fi

sitemap_url="$1"

echo "Pulling $sitemap_url"

# Fetch the sitemaps from the provided root sitemap URL
sitemaps=$(curl -sL "$sitemap_url" | awk -F '[<>]' '/loc/{print $3}')

# Count the number of sitemaps found.
count=$(echo "$sitemaps" | wc -l | tr -d ' ')

echo "Found $count sitemaps"

index=1

# Iterate through the sitemaps and preload them.
while read -r sitemap; do
    echo "Preloading $index/$count $sitemap"
    curl -s "$sitemap" > /dev/null
    index=$((index + 1))
done <<< "$sitemaps"

echo "Sitemaps preloaded."
