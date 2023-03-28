#!/bin/bash

set -e

SITEMAP_URL=$1

echo "Pulling $SITEMAP_URL"

sitemaps=$(curl -L "$SITEMAP_URL" | awk -F '[<>]' '/loc/{print $3}')
count=$(echo "$sitemaps" | grep -c '')
i=1

echo "Found $count sitemaps"

for sitemap in $sitemaps; do
  echo "Preloading $i/$count $sitemap"
  curl -s "$sitemap" > /dev/null
  i=$((i + 1))
done

echo "Sitemaps preloaded."
