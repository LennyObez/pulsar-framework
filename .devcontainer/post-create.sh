#!/usr/bin/env bash
# Post-create initialisation for the Pulsar Framework devcontainer.
# Runs once after the container is built; idempotent for subsequent
# `devcontainer rebuild` invocations.

set -euo pipefail

echo "→ Pulsar Framework devcontainer post-create"

# 1. Pin rustup toolchain to rust-toolchain.toml so the workspace
#    is buildable on first `cargo check` without an extra round-trip.
if [[ -f rust-toolchain.toml ]]; then
  rustup show active-toolchain >/dev/null 2>&1 || rustup show >/dev/null
fi

# 2. Install cargo plugins required by the quality-gate sequence.
#    --locked enforces deterministic versions; cargo-binstall accelerates
#    the install path by pulling pre-built binaries when available.
cargo install --quiet cargo-binstall

declare -a PLUGINS=(
  cargo-nextest
  cargo-llvm-cov
  cargo-mutants
  cargo-audit
  cargo-deny
  cargo-fuzz
  cargo-machete
  cargo-msrv
  cargo-expand
  cargo-watch
  cargo-edit
  cargo-outdated
  cargo-bloat
  cargo-cyclonedx
  sccache
)

for plugin in "${PLUGINS[@]}"; do
  if ! command -v "$plugin" >/dev/null 2>&1 \
    && ! cargo --list 2>/dev/null | grep -q "^\s*${plugin#cargo-}\b"; then
    echo "→ Installing $plugin"
    cargo binstall --no-confirm "$plugin" \
      || cargo install --quiet --locked "$plugin"
  fi
done

# 3. Install mdBook + preprocessors used by `docs/book/`.
declare -a MDBOOK=(
  mdbook
  mdbook-linkcheck
  mdbook-toc
  mdbook-mermaid
  mdbook-admonish
)

for tool in "${MDBOOK[@]}"; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "→ Installing $tool"
    cargo binstall --no-confirm "$tool" \
      || cargo install --quiet --locked "$tool"
  fi
done

# 4. Install supply-chain tooling consumed by .github/workflows/publish.yml
#    and .github/workflows/audit.yml so contributors can dry-run locally.
if ! command -v cosign >/dev/null 2>&1; then
  echo "→ Installing cosign"
  COSIGN_VERSION="v2.4.1"
  curl -sSL "https://github.com/sigstore/cosign/releases/download/${COSIGN_VERSION}/cosign-linux-amd64" \
    -o /tmp/cosign \
    && chmod +x /tmp/cosign \
    && sudo mv /tmp/cosign /usr/local/bin/cosign
fi

if ! command -v syft >/dev/null 2>&1; then
  echo "→ Installing syft"
  curl -sSfL https://raw.githubusercontent.com/anchore/syft/main/install.sh \
    | sudo sh -s -- -b /usr/local/bin
fi

if ! command -v actionlint >/dev/null 2>&1; then
  echo "→ Installing actionlint"
  bash <(curl -sSL https://raw.githubusercontent.com/rhysd/actionlint/main/scripts/download-actionlint.bash) \
    && sudo mv ./actionlint /usr/local/bin/
fi

# 5. Install pre-commit hooks if config exists.
if [[ -f .pre-commit-config.yaml ]]; then
  echo "→ Installing pre-commit hooks"
  pip install --user --quiet pre-commit
  pre-commit install --hook-type pre-commit --hook-type commit-msg
fi

# 6. Configure git for commit signing — but ONLY if the user already
#    has a GPG key bound to their git email. Never auto-generate keys.
if git config --get user.email >/dev/null 2>&1 && command -v gpg >/dev/null 2>&1; then
  GIT_EMAIL=$(git config --get user.email)
  if gpg --list-secret-keys --keyid-format=long "${GIT_EMAIL}" >/dev/null 2>&1; then
    KEY_ID=$(gpg --list-secret-keys --keyid-format=long "${GIT_EMAIL}" \
      | awk '/^sec/ { split($2, a, "/"); print a[2]; exit }')
    echo "→ GPG signing key detected (${KEY_ID}); enabling commit.gpgsign"
    git config commit.gpgsign true
    git config user.signingkey "${KEY_ID}"
    git config gpg.program "$(command -v gpg)"
  else
    echo "ⓘ No GPG key found for ${GIT_EMAIL}; commit signing remains disabled."
    echo "  Generate one with: gpg --full-generate-key  (Ed25519 per Decision 2.30)"
  fi
fi

# 7. Warm the Cargo cache so the first `cargo check` is fast.
echo "→ Warming Cargo cache (cargo fetch)"
cargo fetch --quiet || true

echo "✓ Post-create complete. Try: cargo check --workspace"
