#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TWA_DIR="$ROOT_DIR/twa"
RELEASE_DIR="$ROOT_DIR/release"

export ANDROID_HOME="${ANDROID_HOME:-$HOME/.android-sdk}"
export ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-$ANDROID_HOME}"
export JAVA_HOME="${JAVA_HOME:-/usr/lib/jvm/java-17-openjdk-amd64}"

if [[ ! -f "$ROOT_DIR/keystore/signing.properties" ]]; then
  echo "Missing $ROOT_DIR/keystore/signing.properties" >&2
  exit 1
fi

mkdir -p "$RELEASE_DIR"
cd "$TWA_DIR"
./gradlew bundleRelease assembleRelease

cp app/build/outputs/bundle/release/app-release.aab "$RELEASE_DIR/psilocybin-research-tracker-v1.aab"
cp app/build/outputs/apk/release/app-release.apk "$RELEASE_DIR/psilocybin-research-tracker-v1.apk"
sha256sum "$RELEASE_DIR/psilocybin-research-tracker-v1.aab" > "$RELEASE_DIR/psilocybin-research-tracker-v1.aab.sha256"
sha256sum "$RELEASE_DIR/psilocybin-research-tracker-v1.apk" > "$RELEASE_DIR/psilocybin-research-tracker-v1.apk.sha256"

echo "Release artifacts written to $RELEASE_DIR"
