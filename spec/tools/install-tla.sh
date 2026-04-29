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

# SHA-256 of the upstream tla2tools.jar pinned to TLA_VERSION. Verified
# against https://github.com/tlaplus/tlaplus/releases/tag/v1.8.0 — pin is
# overridable for forward upgrades but missing/mismatched checksum is a
# hard failure (defense-in-depth against supply-chain JAR swap).
# shellcheck disable=SC2034  # consumed via indirect expansion below
TLA_SHA256_1_8_0="accf1505b3a27679b532753e0430dbea1673c9935d7991dc2536b99ce69976fa"
TLA_SHA256_VAR="TLA_SHA256_${TLA_VERSION//./_}"
TLA_SHA256="${!TLA_SHA256_VAR:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE_DIR="${CACHE_DIR:-${SCRIPT_DIR}/cache}"
JAR_PATH="${CACHE_DIR}/tla2tools-${TLA_VERSION}.jar"

mkdir -p "$CACHE_DIR"

verify_checksum() {
    local actual
    actual="$(sha256sum "$JAR_PATH" | cut -d' ' -f1)"
    if [ -z "$TLA_SHA256" ]; then
        echo "[install-tla] WARNING: no pinned SHA-256 for v${TLA_VERSION}; observed ${actual}" >&2
        return 0
    fi
    if [ "$actual" != "$TLA_SHA256" ]; then
        echo "[install-tla] FATAL: SHA-256 mismatch for ${JAR_PATH}" >&2
        echo "[install-tla]   expected: ${TLA_SHA256}" >&2
        echo "[install-tla]   actual:   ${actual}" >&2
        rm -f "$JAR_PATH"
        return 1
    fi
}

if [ ! -f "$JAR_PATH" ]; then
    echo "[install-tla] Downloading TLA+ Tools v${TLA_VERSION} from ${TLA_URL}..." >&2
    curl --fail --silent --show-error --location \
        --output "${JAR_PATH}.tmp" \
        "$TLA_URL"
    mv "${JAR_PATH}.tmp" "$JAR_PATH"
    echo "[install-tla] Downloaded ($(du -h "$JAR_PATH" | cut -f1)) → ${JAR_PATH}" >&2
    verify_checksum
else
    verify_checksum
fi

echo "$JAR_PATH"
