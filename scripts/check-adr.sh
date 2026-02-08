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
    echo "Add or update an ADR in docs/adr/ explaining the decision."
    echo "Template: docs/adr/0000-template.md"
    exit 1
  fi
fi

echo "PASS: ADR governance check passed."
