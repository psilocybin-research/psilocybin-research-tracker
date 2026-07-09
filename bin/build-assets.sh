#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v esbuild >/dev/null 2>&1; then
  echo "esbuild is required to build minified assets." >&2
  exit 1
fi

esbuild assets/styles.css \
  --minify \
  --loader:.woff2=file \
  --loader:.webp=file \
  --loader:.png=file \
  --loader:.jpg=file \
  --outfile=assets/styles.min.css

esbuild assets/app.js \
  --minify \
  --target=es2019 \
  --outfile=assets/app.min.js

printf 'Built minified assets:\n'
wc -c assets/styles.css assets/styles.min.css assets/app.js assets/app.min.js
