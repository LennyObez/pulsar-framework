#!/usr/bin/env bash
#
# Download TLA+ Tools JAR (tla2tools.jar) for local + CI use.
#
# Outputs the JAR path on STDOUT; informational messages on STDERR so the
# script is safe inside command-substitution.
#
# Usage:
#   spec/tools/install-tla.sh                   # default version
#   TLA_VERSION=1.8.0 spec/tools/install-tla.sh # override version
#
# References:
#   - TLA+ Tools releases: https://github.com/tlaplus/tlaplus/releases
#   - Pulsar plan Section II Decision 2.20 (formal verification: TLA+)

set -euo pipefail

TLA_VERSION="${TLA_VERSION:-1.8.0}"
TLA_URL="https://github.com/tlaplus/tlaplus/releases/download/v${TLA_VERSION}/tla2tools.jar"

# SHA-256 pin per supported TLA_VERSION. Verified against the upstream
# release page (e.g. https://github.com/tlaplus/tlaplus/releases/tag/v1.8.0).
# A pin is REQUIRED for every supported version: both missing pin and
# mismatched checksum are hard failures — defense-in-depth against silent
# supply-chain JAR swap, including via attacker-supplied TLA_VERSION
# overrides. To support a new version, add a 'TLA_SHA256_<version>' line
# below before bumping the default.
# shellcheck disable=SC2034  # consumed via indirect expansion below
TLA_SHA256_1_8_0="accf1505b3a27679b532753e0430dbea1673c9935d7991dc2536b99ce69976fa"
TLA_SHA256_VAR="TLA_SHA256_${TLA_VERSION//./_}"
TLA_SHA256="${!TLA_SHA256_VAR:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE_DIR="${CACHE_DIR:-${SCRIPT_DIR}/cache}"
JAR_PATH="${CACHE_DIR}/tla2tools-${TLA_VERSION}.jar"

# Refuse early if no pin exists for the requested version — better to fail
# before spending bandwidth on a download we'd reject anyway.
if [ -z "$TLA_SHA256" ]; then
    echo "[install-tla] FATAL: no pinned SHA-256 for v${TLA_VERSION}" >&2
    echo "[install-tla]   add 'TLA_SHA256_${TLA_VERSION//./_}=<sha256>' to ${BASH_SOURCE[0]}" >&2
    exit 1
fi

mkdir -p "$CACHE_DIR"

# Verify the SHA-256 of <target> matches the pinned value for TLA_VERSION.
# Missing-pin case is handled at script entry — by the time this runs,
# TLA_SHA256 is non-empty. Mismatched checksum → fatal + remove the
# offending file so the next run re-downloads cleanly.
verify_checksum() {
    local target="$1"
    local actual
    actual="$(sha256sum "$target" | cut -d' ' -f1)"
    if [ "$actual" != "$TLA_SHA256" ]; then
        echo "[install-tla] FATAL: SHA-256 mismatch for ${target}" >&2
        echo "[install-tla]   expected: ${TLA_SHA256}" >&2
        echo "[install-tla]   actual:   ${actual}" >&2
        rm -f "$target"
        return 1
    fi
}

if [ ! -f "$JAR_PATH" ]; then
    echo "[install-tla] Downloading TLA+ Tools v${TLA_VERSION} from ${TLA_URL}..." >&2
    curl --fail --silent --show-error --location \
        --output "${JAR_PATH}.tmp" \
        "$TLA_URL"
    # Verify on the .tmp path BEFORE atomic-moving into place — a corrupt
    # download is removed by verify_checksum and never reaches JAR_PATH,
    # so an interrupted-mid-write run cannot leave a bad JAR cached.
    verify_checksum "${JAR_PATH}.tmp"
    mv "${JAR_PATH}.tmp" "$JAR_PATH"
    echo "[install-tla] Downloaded ($(du -h "$JAR_PATH" | cut -f1)) → ${JAR_PATH}" >&2
else
    verify_checksum "$JAR_PATH"
fi

echo "$JAR_PATH"
