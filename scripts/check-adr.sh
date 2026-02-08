#!/usr/bin/env bash
set -euo pipefail

# Core architecture paths that require an ADR when changed
CORE_PATHS=(
  "src/Core/"
  "src/Container/"
  "src/Routing/"
  "src/Http/"
  "src/Extensibility/"
  "src/Api/"
  "src/Config/"
)

# Escape hatch: skip check if PR has 'adr-exempt' label
if [ -n "${GITHUB_EVENT_PATH:-}" ]; then
  LABELS=$(jq -r '.pull_request.labels[]?.name // empty' "$GITHUB_EVENT_PATH" 2>/dev/null || true)
  if echo "$LABELS" | grep -q '^adr-exempt$'; then
    echo "SKIP: PR labeled 'adr-exempt' — ADR check bypassed."
    echo "Ensure a maintainer approved the exemption."
    exit 0
  fi
fi

# Use GitHub-provided base SHA (fork-safe), fall back to branch name, then HEAD~1
if [ -n "${BASE_SHA:-}" ]; then
  CHANGED=$(git diff --name-only "${BASE_SHA}...HEAD")
elif [ -n "${GITHUB_BASE_REF:-}" ]; then
  CHANGED=$(git diff --name-only "origin/${GITHUB_BASE_REF}...HEAD")
else
  CHANGED=$(git diff --name-only HEAD~1)
fi

CORE_CHANGED=false
TRIGGERING_FILES=()
for path in "${CORE_PATHS[@]}"; do
  while IFS= read -r file; do
    [ -n "$file" ] && CORE_CHANGED=true && TRIGGERING_FILES+=("$file")
  done < <(echo "$CHANGED" | grep "^${path}" || true)
done

if [ "$CORE_CHANGED" = true ]; then
  ADR_COUNT=$(echo "$CHANGED" | grep -c "^docs/adr/.*\.md$" || true)
  if [ "$ADR_COUNT" -eq 0 ]; then
    echo "FAIL: Core architecture paths changed without an ADR."
    echo ""
    echo "Changed core files:"
    for f in "${TRIGGERING_FILES[@]}"; do
      echo "  - $f"
    done
    echo ""
    echo "Options:"
    echo "  1. Add or update an ADR in docs/adr/ (template: docs/adr/0000-template.md)"
    echo "  2. Label the PR 'adr-exempt' if this is a trivial change (requires maintainer approval)"
    exit 1
  fi
fi

echo "PASS: ADR governance check passed."
