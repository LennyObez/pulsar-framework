#!/usr/bin/env bash
# Regenerate docs/adr/INDEX.md from the ADR files present in docs/adr/.
#
# Per CLAUDE.md Section 16 + ADR-0008 sprint-feature-branch contract, the ADR
# index must list every ADR file. This script extracts the metadata from each
# ADR's frontmatter (Status / Date / Sprint / Section II decision references)
# and emits an updated INDEX.md.
#
# Placeholder implementation: emits a warning and exits non-zero. Full
# implementation lands as part of Sprint 0.9+ hardening or Sprint 4.5 docs
# consolidation. Until then INDEX.md is hand-maintained.
#
# Usage: ./tools/scripts/regenerate-adr-index.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ADR_DIR="${REPO_ROOT}/docs/adr"
INDEX="${ADR_DIR}/INDEX.md"

if [[ ! -d "${ADR_DIR}" ]]; then
    echo "error: ${ADR_DIR} does not exist" >&2
    exit 1
fi

echo "regenerate-adr-index.sh: scanning ${ADR_DIR}"
ADR_COUNT=$(find "${ADR_DIR}" -maxdepth 1 -type f -name '[0-9][0-9][0-9][0-9]-*.md' ! -name '0000-template.md' | wc -l)
echo "regenerate-adr-index.sh: found ${ADR_COUNT} numbered ADRs (excluding template)"

if [[ ! -f "${INDEX}" ]]; then
    echo "error: ${INDEX} does not exist; run from repo root or create the file first" >&2
    exit 1
fi

INDEXED_COUNT=$(grep -cE '^\| \[ADR-' "${INDEX}" || true)
echo "regenerate-adr-index.sh: ${INDEXED_COUNT} ADRs currently listed in INDEX.md"

if [[ "${ADR_COUNT}" -ne "${INDEXED_COUNT}" ]]; then
    echo "warning: ${ADR_COUNT} ADR files vs ${INDEXED_COUNT} INDEX entries — index is out of sync" >&2
    echo "warning: full regeneration not yet implemented; manually update INDEX.md" >&2
    echo "warning: see tools/xtask/src/main.rs Command::AdrIndex for the planned implementation" >&2
    exit 2
fi

echo "regenerate-adr-index.sh: ADR file count matches INDEX entries — no action needed"
