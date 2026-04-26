#!/usr/bin/env bash
# Regenerate docs/api-surface.md from the public surface of every workspace crate.
#
# Per plan Section VI quality gate "API surface" + Section 17.18 public API
# markers, every PR that modifies a public item must regenerate this file.
# CI verifies the file matches the compiled surface via a drift check.
#
# Implementation strategy (Sprint 4.5 docs consolidation):
#   1. cargo doc --workspace --no-deps --document-private-items=false
#   2. parse docs/json/<crate>.json (rustdoc JSON output, --output-format json)
#   3. emit a compact markdown digest per crate listing every #[api(since)]
#      annotated public item with stability tier
#   4. compare against the on-disk docs/api-surface.md; non-zero exit on drift
#
# Placeholder implementation: emits a warning until rustdoc-json is stable
# enough on rustc 1.95.0 stable for this use. Until then docs/api-surface.md
# is hand-maintained per per-sprint API-surface review.
#
# Usage: ./tools/scripts/regenerate-api-surface.sh > docs/api-surface.md

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SURFACE="${REPO_ROOT}/docs/api-surface.md"

echo "regenerate-api-surface.sh: scanning workspace public surface"
CRATE_COUNT=$(find "${REPO_ROOT}/crates" -maxdepth 1 -type d -name 'pulsar-*' | wc -l)
echo "regenerate-api-surface.sh: ${CRATE_COUNT} workspace crates discovered"

echo "warning: rustdoc-json-based regeneration not yet implemented" >&2
echo "warning: see tools/xtask/src/main.rs Command::ApiSurface for the planned implementation" >&2
echo "warning: hand-maintain ${SURFACE} per per-sprint API-surface review until this lands" >&2

exit 2
