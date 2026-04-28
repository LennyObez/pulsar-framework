#!/usr/bin/env bash
#
# vendor-hacl.sh — re-vendor the HACL* C distribution under
# crates/pulsar-crypto-hacl-bindings/hacl-c/ for SLSA Level 4
# reproducibility per Decision 2.57 + Decision 2.60 + ADR-0009.
#
# This script is the authoritative re-vendoring procedure: any update to
# the upstream HACL* commit pin must run through it so the resulting
# tree matches MANIFEST.sha256 byte-for-byte. Anyone can re-run it to
# verify the vendored tree was produced correctly from the recorded
# upstream commit.
#
# Usage:
#   tools/scripts/vendor-hacl.sh                        # re-vendor at the pinned commit (default)
#   tools/scripts/vendor-hacl.sh <commit-sha>           # re-vendor at a different commit (then update HACL_VERSION)
#   tools/scripts/vendor-hacl.sh --verify               # verify the committed MANIFEST.sha256 matches the current hacl-c/ tree (CI-friendly, no clone)
#
# The script is idempotent: re-running on an already-vendored tree
# overwrites the tree from a fresh upstream clone, regenerates
# MANIFEST.sha256, and the resulting diff must be empty if the upstream
# commit pin is unchanged.
#
# Per Decision 2.60: this script vendors HACL* for the 12 classical
# primitives only. The 4 NIST PQC primitives (ML-KEM-768/1024, ML-DSA-65/87)
# come from the libcrux Rust-native workspace dependencies and are NOT
# vendored here.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
HACL_C_DIR="$REPO_ROOT/crates/pulsar-crypto-hacl-bindings/hacl-c"

# ── --verify mode: recompute manifest, compare against committed ──
# Detects byte-level tampering with hacl-c/ without needing network or
# HACL* upstream clone. Suitable for CI verification on every push.
if [[ "${1:-}" == "--verify" ]]; then
  if [[ ! -f "$HACL_C_DIR/MANIFEST.sha256" ]]; then
    echo "✗ MANIFEST.sha256 missing under $HACL_C_DIR/ — cannot verify." >&2
    exit 2
  fi
  EXPECTED="$HACL_C_DIR/MANIFEST.sha256"
  ACTUAL="$(mktemp -t hacl-manifest-actual-XXXXXX)"
  trap 'rm -f "$ACTUAL"' EXIT
  ( cd "$HACL_C_DIR" && \
    find src include -type f \( -name '*.c' -o -name '*.h' -o -name '*.S' -o -name '*.asm' \) -print0 \
      | sort -z \
      | xargs -0 sha256sum > "$ACTUAL" )
  if diff --brief "$EXPECTED" "$ACTUAL" > /dev/null 2>&1; then
    ENTRIES=$(wc -l < "$EXPECTED")
    echo "✓ MANIFEST.sha256 verified — $ENTRIES files match committed manifest."
    exit 0
  else
    echo "✗ MANIFEST.sha256 mismatch — vendored hacl-c/ tree drifted from committed manifest." >&2
    echo "  Expected manifest: $EXPECTED" >&2
    echo "  Recomputed manifest: $ACTUAL" >&2
    echo "  Diff (lines unique to expected ↑ / actual ↓):" >&2
    diff "$EXPECTED" "$ACTUAL" | head -40 >&2
    exit 1
  fi
fi

# ── Default mode: re-vendor at the pinned (or override) commit ──
# Default upstream commit — the pin captured in HACL_VERSION at the
# Sprint 1.1.A initial vendoring. Override via CLI argument.
DEFAULT_COMMIT="504c2987452f87fe44bce9b9f12e19d6e051761f"
COMMIT="${1:-$DEFAULT_COMMIT}"

WORK_DIR="$(mktemp -d -t hacl-vendoring-XXXXXX)"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "→ Vendoring HACL* at commit $COMMIT"
echo "→ Target: $HACL_C_DIR"
echo "→ Work dir: $WORK_DIR"

# Step 1 — clone HACL* upstream + checkout the pinned commit.
echo "→ Cloning HACL* upstream"
git clone --quiet https://github.com/hacl-star/hacl-star.git "$WORK_DIR/hacl-star-src"
( cd "$WORK_DIR/hacl-star-src" && git checkout --quiet "$COMMIT" )

# Step 2 — wipe the existing vendored tree (preserves LICENSE.txt,
# HACL_VERSION, MANIFEST.sha256 which are regenerated below).
echo "→ Wiping previous vendored tree"
rm -rf "$HACL_C_DIR/src" "$HACL_C_DIR/include"
mkdir -p \
  "$HACL_C_DIR/src" \
  "$HACL_C_DIR/include/internal" \
  "$HACL_C_DIR/include/krml" \
  "$HACL_C_DIR/include/krmllib/dist/minimal"

# Step 3 — copy sources + headers from dist/gcc-compatible/.
SRC="$WORK_DIR/hacl-star-src/dist/gcc-compatible"
echo "→ Copying *.c, *.S, *.asm to src/"
cp "$SRC"/*.c   "$HACL_C_DIR/src/"
cp "$SRC"/*.S   "$HACL_C_DIR/src/"
cp "$SRC"/*.asm "$HACL_C_DIR/src/"

echo "→ Copying *.h to include/"
cp "$SRC"/*.h           "$HACL_C_DIR/include/"
cp "$SRC"/internal/*.h  "$HACL_C_DIR/include/internal/"

# Step 4 — copy karamel runtime + krmllib minimal F* shims.
KARAMEL="$WORK_DIR/hacl-star-src/dist/karamel"
echo "→ Copying karamel runtime + krmllib minimal F* shims"
cp -r "$KARAMEL/include/krml/"*           "$HACL_C_DIR/include/krml/"
cp    "$KARAMEL/krmllib/dist/minimal/"*.h "$HACL_C_DIR/include/krmllib/dist/minimal/"

# Step 5 — capture LICENSE.
echo "→ Copying HACL* dist LICENSE"
cp "$WORK_DIR/hacl-star-src/dist/LICENSE.txt" "$HACL_C_DIR/LICENSE.txt"

# Step 6 — record version metadata.
INFO_TXT="$SRC/INFO.txt"
F_STAR_VER="$(grep -E '^F\* version:' "$INFO_TXT" | awk '{print $NF}' || echo unknown)"
KARAMEL_VER="$(grep -E '^Karamel version:' "$INFO_TXT" | awk '{print $NF}' || echo unknown)"
VALE_VER="$(grep -E '^Vale version:' "$INFO_TXT" | awk '{print $NF}' || echo unknown)"
COMMIT_DATE="$( cd "$WORK_DIR/hacl-star-src" && git show -s --format=%cI HEAD )"

cat > "$HACL_C_DIR/HACL_VERSION" <<EOF
upstream_repo: https://github.com/hacl-star/hacl-star
upstream_commit: $COMMIT
upstream_date: $COMMIT_DATE
upstream_branch: main
extracted_subtree: dist/gcc-compatible (selective) + dist/karamel/include + dist/karamel/krmllib/dist/minimal
toolchain:
  fstar_version: $F_STAR_VER
  karamel_version: $KARAMEL_VER
  vale_version: $VALE_VER
vendored_at: $(date -u +%Y-%m-%d)
license: Apache-2.0 (see LICENSE.txt)
EOF

# Step 7 — regenerate MANIFEST.sha256 from the resulting tree.
echo "→ Regenerating MANIFEST.sha256"
( cd "$HACL_C_DIR" && \
  find src include -type f \( -name '*.c' -o -name '*.h' -o -name '*.S' -o -name '*.asm' \) -print0 \
    | sort -z \
    | xargs -0 sha256sum > MANIFEST.sha256 )

# Step 8 — summary.
ENTRIES=$(wc -l < "$HACL_C_DIR/MANIFEST.sha256")
TOTAL_SIZE=$(du -sh "$HACL_C_DIR" | cut -f1)
echo
echo "✓ Vendoring complete"
echo "  Commit: $COMMIT"
echo "  Files vendored: $ENTRIES"
echo "  Total size: $TOTAL_SIZE"
echo
echo "Next: review the diff with 'git diff $HACL_C_DIR' and commit if intended."
