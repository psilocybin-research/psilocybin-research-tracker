#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

ESBUILD="./node_modules/.bin/esbuild"

if [[ ! -x "$ESBUILD" ]]; then
  echo "Pinned frontend dependencies are missing. Run: npm ci" >&2
  exit 1
fi

"$ESBUILD" assets/styles.css \
  --minify \
  --loader:.woff2=file \
  --loader:.webp=file \
  --loader:.png=file \
  --loader:.jpg=file \
  --outfile=assets/styles.min.css

"$ESBUILD" assets/app.js \
  --minify \
  --target=es2019 \
  --outfile=assets/app.min.js

printf 'Built minified assets:\n'
wc -c assets/styles.css assets/styles.min.css assets/app.js assets/app.min.js
