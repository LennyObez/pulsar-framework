#!/usr/bin/env bash
#
# Install the Creusot deductive-verification toolchain for pulsar-kernel.
#
# Provisions:
#   1. System packages (apt): opam, why3, z3, cvc5, libzmq3-dev
#   2. opam initialisation (--bare, no sandboxing — sandboxing is unwanted
#      under WSL2 + breaks on rootless containers)
#   3. Creusot v0.11.0 toolchain via the upstream INSTALL script:
#        - cargo-creusot      (cargo subcommand)
#        - creusot-rustc      (compiler driver)
#        - Why3 via opam      (the version Creusot targets)
#        - Creusot prelude    (Why3 library)
#
# Idempotent: re-running on a configured machine is a no-op.
#
# Prerequisites:
#   - Ubuntu 24.04+ (or any Debian-derivative with apt + opam ≥ 2.1)
#   - Rust nightly toolchain matching Creusot v0.11.0's pin
#     (nightly-2026-04-21) — auto-installed by rustup via Creusot's
#     rust-toolchain file when the cargo invocation triggers it.
#   - `sudo` access for the apt step (system packages).
#
# References:
#   - Creusot project: https://github.com/creusot-rs/creusot
#   - ADR-0015 — Creusot for kernel function contracts
#   - Pulsar plan Section II Decision 2.20 (Creusot + TLA+)

set -euo pipefail

CREUSOT_VERSION="${CREUSOT_VERSION:-0.11.0}"
CREUSOT_REPO="https://github.com/creusot-rs/creusot.git"
CREUSOT_CHECKOUT_DIR="${CREUSOT_CHECKOUT_DIR:-${HOME}/.local/share/creusot/src}"

log() { echo "[install-creusot] $*" >&2; }
fatal() { log "FATAL: $*"; exit 1; }

# -----------------------------------------------------------------------------
# Step 1 — system packages
# -----------------------------------------------------------------------------
log "Step 1/4: ensuring apt packages (opam, why3, z3, cvc5, libzmq3-dev)..."
if ! command -v sudo >/dev/null 2>&1; then
    fatal "sudo is required to install apt packages; aborting"
fi
sudo apt-get update -qq
sudo apt-get install -y -qq opam why3 z3 cvc5 libzmq3-dev

# -----------------------------------------------------------------------------
# Step 2 — opam init (idempotent)
# -----------------------------------------------------------------------------
log "Step 2/4: ensuring opam is initialised..."
if [ ! -d "${HOME}/.opam" ]; then
    opam init --bare --disable-sandboxing -y --no-setup
else
    log "  (opam already initialised at ${HOME}/.opam — skipping init)"
fi
opam --cli=2.1 var --global in-creusot-ci=true >/dev/null

# -----------------------------------------------------------------------------
# Step 3 — Creusot source checkout (pinned to CREUSOT_VERSION)
# -----------------------------------------------------------------------------
log "Step 3/4: ensuring Creusot v${CREUSOT_VERSION} source is checked out..."
mkdir -p "$(dirname "${CREUSOT_CHECKOUT_DIR}")"
if [ ! -d "${CREUSOT_CHECKOUT_DIR}/.git" ]; then
    git clone --branch "v${CREUSOT_VERSION}" --depth 1 "${CREUSOT_REPO}" "${CREUSOT_CHECKOUT_DIR}"
else
    log "  (already cloned at ${CREUSOT_CHECKOUT_DIR})"
    (cd "${CREUSOT_CHECKOUT_DIR}" && git fetch --depth 1 origin "v${CREUSOT_VERSION}" && git checkout -q "v${CREUSOT_VERSION}")
fi

# -----------------------------------------------------------------------------
# Step 4 — INSTALL via cargo-driven creusot-install
# -----------------------------------------------------------------------------
log "Step 4/4: running Creusot's ./INSTALL (this builds + opam-installs Why3 + provers; expect 10–25 minutes on first run)..."
cd "${CREUSOT_CHECKOUT_DIR}"
eval "$(opam env)"
./INSTALL

# -----------------------------------------------------------------------------
# Smoke test
# -----------------------------------------------------------------------------
log "Verifying installation..."
if ! command -v cargo-creusot >/dev/null 2>&1; then
    fatal "cargo-creusot not found in PATH after install — check ~/.cargo/bin is on PATH"
fi
cargo creusot --help >/dev/null 2>&1 || fatal "cargo creusot --help failed"

log "Done. Run 'cargo creusot prove' inside crates/pulsar-kernel/ to discharge contracts."
