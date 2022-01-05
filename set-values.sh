#!/usr/bin/env bash

set -euo pipefail

if [[ -z "$2" ]]; then
    echo "Usage: $0 <events> <ratio>"
    exit 1
fi

SCRIPT="/home/mike/dev/phpfarm/src/php-8.2.28/update-wp-config"

ssh hydra "$SCRIPT --events '$1' --ratio '$2'" || {
    echo "Failed to execute $SCRIPT on ubuntu"
    exit 1
}
