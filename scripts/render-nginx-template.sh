#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 || "$1" != /* || "$2" != /* ]]; then
    echo "Usage: scripts/render-nginx-template.sh <absolute-public-path> <absolute-output-path>" >&2
    exit 64
fi

PUBLIC_PATH="${1%/}"
OUTPUT_PATH="$2"
if [[ ! "$PUBLIC_PATH" =~ ^/[A-Za-z0-9._/-]+$ || ! "$OUTPUT_PATH" =~ ^/[A-Za-z0-9._/-]+$ || "$PUBLIC_PATH" == *".."* || "$OUTPUT_PATH" == *".."* ]]; then
    echo "Paths must be absolute, traversal-free, and contain only safe path characters." >&2
    exit 64
fi

sed "s|@@MXCENTRAL_PUBLIC_PATH@@|${PUBLIC_PATH}|g" \
    "$(dirname "$0")/../mxcentral-for-iRedmail/docs/nginx/mxcentral.tmpl" > "$OUTPUT_PATH"

if grep -q '@@MXCENTRAL_PUBLIC_PATH@@' "$OUTPUT_PATH"; then
    echo "Nginx template rendering did not replace every deployment token." >&2
    exit 1
fi
