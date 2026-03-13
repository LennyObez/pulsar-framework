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

# Escape hatch: skip check when an ADR exemption has been formally
# approved. F28.3: previously the gate accepted the `adr-exempt`
# label alone — anyone with write access could apply the label to
# their own PR and bypass the architectural review without leaving
# any audit trail. The gate now demands TWO independent signals:
#
#   (1) the `adr-exempt` label is present; AND
#   (2) a maintainer left a review-comment containing the literal
#       phrase `/adr-exempt-approved` somewhere in its body.
#
# The maintainer set is sourced from the repo's CODEOWNERS file —
# the comment author must appear in a CODEOWNERS line or the
# bypass is refused. Self-approval is rejected: the comment author
# must differ from the PR author.
#
# This converts the bypass from "policy-by-trust" into a recorded
# two-party approval that survives in the GitHub review history,
# without requiring repo-settings changes (which the script
# cannot self-apply).
if [ -n "${GITHUB_EVENT_PATH:-}" ]; then
  LABELS=$(jq -r '.pull_request.labels[]?.name // empty' "$GITHUB_EVENT_PATH" 2>/dev/null || true)
  if echo "$LABELS" | grep -q '^adr-exempt$'; then
    PR_NUMBER=$(jq -r '.pull_request.number // empty' "$GITHUB_EVENT_PATH" 2>/dev/null || true)
    PR_AUTHOR=$(jq -r '.pull_request.user.login // empty' "$GITHUB_EVENT_PATH" 2>/dev/null || true)
    REPO_FULL=$(jq -r '.repository.full_name // empty' "$GITHUB_EVENT_PATH" 2>/dev/null || true)

    if [ -z "${PR_NUMBER:-}" ] || [ -z "${REPO_FULL:-}" ]; then
      echo "FAIL: PR metadata unavailable; cannot validate adr-exempt approval."
      exit 1
    fi

    if ! command -v gh >/dev/null 2>&1; then
      echo "FAIL: 'gh' CLI not available; cannot validate adr-exempt approval."
      exit 1
    fi

    # Maintainer list = unique GitHub handles in CODEOWNERS,
    # stripping the leading `@`. CODEOWNERS path patterns are
    # ignored — any developer named in any rule counts as a
    # maintainer for the purpose of this approval.
    if [ ! -f ".github/CODEOWNERS" ] && [ ! -f "CODEOWNERS" ] && [ ! -f "docs/CODEOWNERS" ]; then
      echo "FAIL: CODEOWNERS file missing; adr-exempt approval requires a maintainer list."
      exit 1
    fi
    CODEOWNERS_FILE=$([ -f ".github/CODEOWNERS" ] && echo ".github/CODEOWNERS" \
      || ([ -f "CODEOWNERS" ] && echo "CODEOWNERS" \
      || echo "docs/CODEOWNERS"))
    MAINTAINERS=$(grep -oE '@[A-Za-z0-9_-]+' "$CODEOWNERS_FILE" | tr -d '@' | sort -u)

    APPROVAL_AUTHOR=$(gh api "repos/${REPO_FULL}/issues/${PR_NUMBER}/comments" \
      --jq '.[] | select(.body | contains("/adr-exempt-approved")) | .user.login' \
      2>/dev/null | head -n1 || true)

    if [ -z "${APPROVAL_AUTHOR:-}" ]; then
      echo "FAIL: PR has 'adr-exempt' label but no '/adr-exempt-approved' comment."
      echo "A maintainer (CODEOWNERS) must comment '/adr-exempt-approved <reason>'."
      exit 1
    fi

    if [ "$APPROVAL_AUTHOR" = "$PR_AUTHOR" ]; then
      echo "FAIL: adr-exempt self-approval refused (author=$PR_AUTHOR also wrote /adr-exempt-approved)."
      exit 1
    fi

    if ! echo "$MAINTAINERS" | grep -qx "$APPROVAL_AUTHOR"; then
      echo "FAIL: '/adr-exempt-approved' comment by $APPROVAL_AUTHOR but they are not in CODEOWNERS."
      exit 1
    fi

    echo "SKIP: PR labeled 'adr-exempt' and approved by maintainer '$APPROVAL_AUTHOR'."
    echo "Recorded for audit: PR #$PR_NUMBER, author=$PR_AUTHOR, approver=$APPROVAL_AUTHOR."
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
  # F28.6: count only numbered ADRs (NNNN-...). The previous pattern
  # `^docs/adr/.*\.md$` matched the template (`0000-template.md`) and
  # any unrelated `.md` under adr/ — a typo fix on the template alone
  # was enough to satisfy the gate without writing an actual ADR.
  ADR_COUNT=$(echo "$CHANGED" | grep -E "^docs/adr/0*[1-9][0-9]*-.*\.md$" | grep -v "^docs/adr/0000-" | wc -l)
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
