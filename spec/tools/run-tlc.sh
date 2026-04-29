#!/usr/bin/env bash
#
# Run the TLC model checker on a Pulsar TLA+ specification.
#
# Usage:
#   spec/tools/run-tlc.sh <spec-name>
#
# Where <spec-name> is the basename (without `.tla` extension) of a spec
# under spec/. Example:
#
#   spec/tools/run-tlc.sh crypto
#
# The script downloads tla2tools.jar on first run (cached under
# spec/tools/cache/), then invokes:
#
#   java -cp <jar> tlc2.TLC -config <spec>.cfg -workers auto <spec>.tla
#
# Pulsar plan Section VI quality-gate reference for kernel + protocol
# sprints (Sprint 1.1 / 1.2 / 1.3 / 1.4 / 1.5 / 2.5 / 3B.2 / 3E.1).

set -euo pipefail

SPEC_NAME="${1:-}"
if [ -z "$SPEC_NAME" ]; then
    echo "usage: $0 <spec-name>" >&2
    echo "       (spec-name is the basename of a .tla file under spec/)" >&2
    exit 64
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SPEC_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
TLA_FILE="${SPEC_DIR}/${SPEC_NAME}.tla"
CFG_FILE="${SPEC_DIR}/${SPEC_NAME}.cfg"

if [ ! -f "$TLA_FILE" ]; then
    echo "[run-tlc] spec not found: ${TLA_FILE}" >&2
    exit 66
fi
if [ ! -f "$CFG_FILE" ]; then
    echo "[run-tlc] config not found: ${CFG_FILE}" >&2
    exit 66
fi

JAR_PATH="$("${SCRIPT_DIR}/install-tla.sh")"
STATES_DIR="${SCRIPT_DIR}/cache/tlc-states-${SPEC_NAME}"
mkdir -p "$STATES_DIR"

echo "[run-tlc] Checking ${SPEC_NAME}.tla against ${SPEC_NAME}.cfg..." >&2

cd "$SPEC_DIR"
exec java \
    -XX:+UseParallelGC \
    -cp "$JAR_PATH" \
    tlc2.TLC \
    -config "${SPEC_NAME}.cfg" \
    -workers auto \
    -metadir "$STATES_DIR" \
    "${SPEC_NAME}.tla"
