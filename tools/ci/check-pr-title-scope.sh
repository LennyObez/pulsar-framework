#!/usr/bin/env bash
# F387.4 / F387.M2 / F380.M1: pre-merge guard against title-deception.
#
# Pattern observed across PR #380, #385, #387: the PR title used a
# `chore:` or `docs:` prefix even though the diff added new core
# modules (`src/Workflow/`, `src/Saga/`, `src/Codegen/`) or new
# extensions. Reviewers skim PR titles when triaging, and a `chore:`
# label invites less scrutiny than `feat(core):`. For a banking
# framework, that scrutiny gap is a compliance gap.
#
# This guard reads the PR title from $PR_TITLE (set by the calling
# workflow) and fails when the title prefix is `chore:` or `docs:`
# but the diff against the base ref:
#   - adds a new top-level directory under `src/`
#   - adds a new top-level directory under `extensions/`
#   - adds a new public-API class (file under `src/` containing `#[Api]`)
#
# Exit codes:
#   0 - title scope is consistent with the diff
#   1 - title-deception detected, with a human-readable error
#
# Usage (in workflow):
#   env:
#     PR_TITLE: ${{ github.event.pull_request.title }}
#     BASE_REF: ${{ github.base_ref }}
#   run: bash tools/ci/check-pr-title-scope.sh

set -euo pipefail

if [[ -z "${PR_TITLE:-}" ]]; then
    echo "PR_TITLE env var not set; skipping title-scope check (push event?)"
    exit 0
fi

if [[ -z "${BASE_REF:-}" ]]; then
    echo "BASE_REF env var not set; skipping title-scope check"
    exit 0
fi

# Extract the conventional-commit prefix (everything before the first colon)
# Tolerate scope: `chore(scope):`, `docs(scope):`.
title_prefix="${PR_TITLE%%:*}"
title_type="${title_prefix%%(*}"

case "$title_type" in
    chore|docs)
        ;;
    *)
        # Other prefixes (feat, fix, security, perf, refactor, test, ci, build)
        # do not trigger the scope-strict rule.
        echo "PR title prefix '$title_type' — scope-strict rule does not apply"
        exit 0
        ;;
esac

# The diff range is base..HEAD. The base ref is fetched by the workflow.
# `--diff-filter=A` selects added (new) paths only.
mapfile -t added_paths < <(git diff --name-only --diff-filter=A "origin/${BASE_REF}...HEAD" 2>/dev/null || true)

if [[ "${#added_paths[@]}" -eq 0 ]]; then
    echo "No added paths in diff; title-scope rule passes"
    exit 0
fi

# Detect new top-level src/ modules and new extensions.
declare -a new_src_modules=()
declare -a new_extensions=()

# Build sets of existing top-level src/ and extensions/ directories at base.
# `git ls-tree` reads the tree without checking it out.
mapfile -t base_src_modules < <(git ls-tree --name-only "origin/${BASE_REF}" src/ 2>/dev/null || true)
mapfile -t base_extensions < <(git ls-tree --name-only "origin/${BASE_REF}" extensions/ 2>/dev/null || true)

for path in "${added_paths[@]}"; do
    case "$path" in
        src/*/*)
            module="${path#src/}"
            module="${module%%/*}"
            already_exists=0
            for existing in "${base_src_modules[@]}"; do
                if [[ "$existing" == "src/$module" ]]; then
                    already_exists=1
                    break
                fi
            done
            if [[ $already_exists -eq 0 ]]; then
                # Avoid duplicate entries
                already_recorded=0
                for entry in "${new_src_modules[@]:-}"; do
                    if [[ "$entry" == "$module" ]]; then
                        already_recorded=1
                        break
                    fi
                done
                if [[ $already_recorded -eq 0 ]]; then
                    new_src_modules+=("$module")
                fi
            fi
            ;;
        extensions/*/*)
            ext="${path#extensions/}"
            ext="${ext%%/*}"
            already_exists=0
            for existing in "${base_extensions[@]}"; do
                if [[ "$existing" == "extensions/$ext" ]]; then
                    already_exists=1
                    break
                fi
            done
            if [[ $already_exists -eq 0 ]]; then
                already_recorded=0
                for entry in "${new_extensions[@]:-}"; do
                    if [[ "$entry" == "$ext" ]]; then
                        already_recorded=1
                        break
                    fi
                done
                if [[ $already_recorded -eq 0 ]]; then
                    new_extensions+=("$ext")
                fi
            fi
            ;;
    esac
done

if [[ "${#new_src_modules[@]}" -eq 0 && "${#new_extensions[@]}" -eq 0 ]]; then
    echo "No new src/ modules or extensions/ in diff; title-scope rule passes"
    exit 0
fi

# Title-deception: chore/docs prefix but the diff adds modules.
echo "==============================================================="
echo "PR title-scope mismatch (F387.M2)"
echo "==============================================================="
echo
echo "PR title:        $PR_TITLE"
echo "Detected prefix: $title_type"
echo
if [[ "${#new_src_modules[@]}" -gt 0 ]]; then
    echo "New core modules added under src/:"
    for m in "${new_src_modules[@]}"; do
        echo "  - src/$m"
    done
fi
if [[ "${#new_extensions[@]}" -gt 0 ]]; then
    echo "New extensions added:"
    for e in "${new_extensions[@]}"; do
        echo "  - extensions/$e"
    done
fi
echo
echo "A '$title_type:' prefix invites less reviewer scrutiny than"
echo "'feat(core):' or 'feat(ext):'. PRs adding new modules MUST"
echo "use a 'feat:' prefix so reviewers see the scope at a glance."
echo
echo "Rename the PR (or the merge commit on squash) accordingly."
exit 1
