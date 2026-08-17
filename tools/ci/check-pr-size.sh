#!/usr/bin/env bash
# ADR-0031: enforce the 1500-line PR size cap. Review effectiveness
# collapses well before that figure, so a diff above it cannot honestly
# be called peer-reviewed — which matters because that claim is a
# documented change-control for regulated deployments.
#
# Counts lines of *substantive* diff between the PR base and HEAD,
# excluding paths in `.size-limit-ignore` (lockfile updates,
# generated code, vendored fixtures, pure renames). The cap is
# 1500; PRs exceeding it require both:
#   - the `oversize-pr-acknowledged` label, AND
#   - a `/oversize-pr-approved` comment by a CODEOWNER who is not
#     the PR author (same shape as the adr-exempt guard in
#     scripts/check-adr.sh).
#
# Exit codes:
#   0 - PR is under the cap, OR exempt and properly approved
#   1 - PR exceeds the cap and lacks the two-party approval
#
# Usage (from CI workflow):
#   env:
#     PR_NUMBER: ${{ github.event.pull_request.number }}
#     PR_AUTHOR: ${{ github.event.pull_request.user.login }}
#     BASE_REF:  ${{ github.base_ref }}
#     REPO_FULL: ${{ github.repository }}
#   run: bash tools/ci/check-pr-size.sh

set -euo pipefail

LIMIT=1500
IGNORE_FILE=".size-limit-ignore"

if [[ -z "${BASE_REF:-}" ]]; then
    echo "BASE_REF env var not set; skipping PR size check (push event?)"
    exit 0
fi

# Build the exclusion pattern from .size-limit-ignore — one
# pathspec per line, blank lines and `#` comments ignored. Falls
# back to a sane default list when the file is absent so the
# guard works on a fresh clone.
declare -a exclusions=()
if [[ -f "$IGNORE_FILE" ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="${line%"${line##*[![:space:]]}"}"
        line="${line#"${line%%[![:space:]]*}"}"
        if [[ -n "$line" ]]; then
            exclusions+=(":(exclude)$line")
        fi
    done < "$IGNORE_FILE"
else
    exclusions=(
        ":(exclude)composer.lock"
        ":(exclude)pnpm-lock.yaml"
        ":(exclude)package-lock.json"
        ":(exclude)tools/api/public-api.snapshot.json"
        ":(exclude)resources/packs/**/composer.lock"
    )
fi

# Substantive line count = additions + deletions across the diff
# range. Use `--shortstat` for the totals; the `--` separates the
# range from the pathspecs.
shortstat=$(git diff --shortstat "origin/${BASE_REF}...HEAD" -- "${exclusions[@]}" 2>/dev/null || true)

if [[ -z "$shortstat" ]]; then
    echo "No diff against base; PR size check passes trivially"
    exit 0
fi

added=$(echo "$shortstat" | grep -oE '[0-9]+ insertion' | grep -oE '[0-9]+' || echo 0)
deleted=$(echo "$shortstat" | grep -oE '[0-9]+ deletion'  | grep -oE '[0-9]+' || echo 0)
total=$((added + deleted))

echo "PR substantive diff: ${added} insertions + ${deleted} deletions = ${total} lines"

if [[ $total -le $LIMIT ]]; then
    echo "PASS: under the ${LIMIT}-line cap"
    exit 0
fi

echo "PR exceeds the ${LIMIT}-line cap (${total} lines). Checking exemption…"

# Exemption requires both signals (mirrors the adr-exempt guard):
# label present AND CODEOWNER approval comment. One alone is
# self-service; two make the exemption a two-party decision.
if [[ -z "${PR_NUMBER:-}" || -z "${REPO_FULL:-}" ]]; then
    echo "FAIL: PR metadata unavailable; cannot check exemption"
    exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
    echo "FAIL: 'gh' CLI not available; cannot validate oversize approval"
    exit 1
fi

# Label check
labels=$(gh pr view "$PR_NUMBER" --repo "$REPO_FULL" --json labels --jq '.labels[].name' 2>/dev/null || true)
if ! echo "$labels" | grep -qx 'oversize-pr-acknowledged'; then
    echo "FAIL: PR exceeds ${LIMIT}-line cap and lacks 'oversize-pr-acknowledged' label."
    echo "      Either split the PR (preferred) or apply the label and request approval."
    echo "      ADR-0031 §1 documents the exemption procedure."
    exit 1
fi

# CODEOWNER list — same logic as check-adr.sh
if [[ -f ".github/CODEOWNERS" ]]; then
    codeowners_path=".github/CODEOWNERS"
elif [[ -f "CODEOWNERS" ]]; then
    codeowners_path="CODEOWNERS"
elif [[ -f "docs/CODEOWNERS" ]]; then
    codeowners_path="docs/CODEOWNERS"
else
    echo "FAIL: CODEOWNERS file missing; oversize approval requires a maintainer list"
    exit 1
fi
maintainers=$(grep -oE '@[A-Za-z0-9_-]+' "$codeowners_path" | tr -d '@' | sort -u)

approval_author=$(gh api "repos/${REPO_FULL}/issues/${PR_NUMBER}/comments" \
    --jq '.[] | select(.body | contains("/oversize-pr-approved")) | .user.login' \
    2>/dev/null | head -n1 || true)

if [[ -z "${approval_author:-}" ]]; then
    echo "FAIL: PR has 'oversize-pr-acknowledged' label but no '/oversize-pr-approved' comment."
    echo "      A CODEOWNER must comment '/oversize-pr-approved <reason>'."
    exit 1
fi

maintainer_count=$(echo "$maintainers" | grep -c . || true)

# ADR-0031 §1.1. With one CODEOWNER who is also the author, the independent reviewer
# does not exist, so the rule can only be bypassed by an admin or block forever.
# Self-approval is accepted here and NOWHERE else, and the log says the control was
# unavailable — never that it passed.
if [[ "$approval_author" = "${PR_AUTHOR:-}" ]]; then
    if [[ "$maintainer_count" -ne 1 ]] || ! echo "$maintainers" | grep -qx "${PR_AUTHOR:-}"; then
        echo "FAIL: oversize-pr self-approval refused (author=${PR_AUTHOR} also wrote /oversize-pr-approved)"
        echo "      ADR-0031 §1.1 permits it only when CODEOWNERS names exactly one person"
        echo "      and that person is the author. This repository lists ${maintainer_count}."
        exit 1
    fi

    echo "::warning::ADR-0031 §1.1: two-party approval UNAVAILABLE — ${PR_AUTHOR} is the sole CODEOWNER and the PR author. The independent reviewer attestation of §1 was not obtained; it could not be."
    echo "EXEMPT (single-maintainer): PR exceeds ${LIMIT}-line cap, label present, self-attested by sole CODEOWNER ${PR_AUTHOR}"
    exit 0
fi

if ! echo "$maintainers" | grep -qx "$approval_author"; then
    echo "FAIL: '/oversize-pr-approved' comment by ${approval_author} but they are not in CODEOWNERS"
    exit 1
fi

echo "EXEMPT: PR exceeds ${LIMIT}-line cap, oversize-pr-acknowledged label present, approved by CODEOWNER ${approval_author}"
exit 0
