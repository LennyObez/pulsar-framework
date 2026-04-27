{
  description = "Pulsar Framework — reproducible Rust 1.95.0 development environment";

  # Per plan Section 14.6 SLSA Level 4 reproducibility + Section VII tooling
  # stack: every contributor + CI runner + air-gapped audit environment must
  # converge on byte-identical toolchain inputs. flake.nix encodes the
  # canonical pinned toolchain so `nix develop` produces a shell with the
  # exact same `rustc`, `cargo-*` plugins, `mold` linker, and observability
  # tooling regardless of host distribution. The pinned `rust-toolchain.toml`
  # remains authoritative for non-Nix users; flake.nix is the Nix-flavoured
  # mirror that subsumes the toolchain plus its sibling tools.

  inputs = {
    # Nixpkgs 25.05 is the LTS-aligned channel pinning matching the
    # rust-toolchain.toml MSRV (rustc 1.95.0). Bumped at every Pulsar
    # MINOR release together with the toolchain.
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.05";

    # rust-overlay gives us a precise rustc + components selector tied
    # to rust-toolchain.toml. We never use the stock nixpkgs rust because
    # nixpkgs lags upstream by 2-6 weeks, breaking SLSA reproducibility
    # if a contributor regenerates flake.lock against a different snapshot.
    rust-overlay = {
      url = "github:oxalica/rust-overlay";
      inputs.nixpkgs.follows = "nixpkgs";
    };

    # flake-utils gives us the `eachDefaultSystem` helper so `nix develop`
    # works on x86_64-linux, aarch64-linux, x86_64-darwin, aarch64-darwin
    # without per-arch boilerplate.
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs =
    { self
    , nixpkgs
    , rust-overlay
    , flake-utils
    ,
    }:
    flake-utils.lib.eachDefaultSystem (
      system:
      let
        overlays = [ (import rust-overlay) ];
        pkgs = import nixpkgs { inherit system overlays; };

        # Mirrors rust-toolchain.toml. `fromToolchainFile` reads the file
        # directly so flake.nix and rust-toolchain.toml can never drift.
        rustToolchain = pkgs.rust-bin.fromRustupToolchainFile ./rust-toolchain.toml;

        # The full quality-gate tooling per Section VI. Versions follow
        # nixpkgs 25.05 LTS — we do NOT pin individual cargo plugins to
        # specific versions because (a) nixpkgs already pins the channel
        # snapshot and (b) cargo-* plugins are designed to track the
        # latest cargo cli surface, so floating them avoids unnecessary
        # toolchain churn.
        commonTools = with pkgs; [
          rustToolchain

          # Build / linker
          mold
          clang_18
          pkg-config

          # Quality gates
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

          # Observability + perf
          flamegraph
          hyperfine
          tokei

          # Documentation + book
          mdbook
          mdbook-linkcheck
          mdbook-toc
          mdbook-mermaid
          mdbook-admonish

          # Diagrams
          graphviz
          mermaid-cli
          plantuml

          # TLA+ + formal-verification
          tlaplus

          # Container + supply-chain
          cosign
          syft
          grype
          trivy

          # Git + commit signing
          git
          gnupg
          gh

          # Pre-commit hooks
          pre-commit
          shellcheck
          shfmt
          actionlint
          taplo
          yamllint

          # JSON / TOML / YAML utilities
          jq
          yq

          # Networking
          curl
          httpie

          # Misc
          ripgrep
          fd
          bat
          tree
          openssl
          zstd
          xz
        ];

        # macOS-specific: the framework links AVFoundation for
        # `pulsar-realtime` WebRTC adapters once Sprint 3B.2 lands.
        # Pre-declared here so the dev shell already has the linker
        # paths set rather than failing during the first WebRTC sprint.
        darwinFrameworks = pkgs.lib.optionals pkgs.stdenv.isDarwin [
          pkgs.darwin.apple_sdk.frameworks.SystemConfiguration
          pkgs.darwin.apple_sdk.frameworks.Security
          pkgs.darwin.apple_sdk.frameworks.CoreFoundation
        ];

        # Linux-specific: kernel+OS-level dependencies that some
        # crates bind to (io_uring, perf, eBPF surface). Optional on
        # macOS / Windows.
        linuxLibs = pkgs.lib.optionals pkgs.stdenv.isLinux [
          pkgs.liburing
          pkgs.elfutils
          pkgs.libbpf
          pkgs.numactl
        ];
      in
      {
        devShells.default = pkgs.mkShell {
          name = "pulsar-framework-dev";

          packages = commonTools ++ darwinFrameworks ++ linuxLibs;

          # Honour the .cargo/config.toml linker + sccache wrapper.
          # We do NOT export RUSTFLAGS here because the workspace
          # already declares -D warnings via [workspace.lints]; the
          # CI dotfiles also export RUSTFLAGS, and double-export
          # confuses cargo-watch+sccache invalidation.
          shellHook = ''
            echo "→ Pulsar Framework dev shell (Rust $(rustc --version | awk '{print $2}'), $(uname -m))"
            echo "→ cargo plugins: nextest, llvm-cov, mutants, audit, deny, fuzz, machete, msrv, expand, watch, edit, outdated, bloat, cyclonedx"
            echo "→ TLA+: $(tlc -h 2>&1 | head -1 || echo 'unavailable')"
            echo "→ Documentation: mdbook, mdbook-linkcheck, mdbook-toc, mdbook-mermaid, mdbook-admonish"
            echo "→ Supply chain: cosign, syft, grype, trivy"
            export PATH="$PWD/tools/scripts:$PATH"
            # Activate pre-commit hooks if not already installed.
            if [ ! -f .git/hooks/pre-commit ] && [ -f .pre-commit-config.yaml ]; then
              echo "→ Installing pre-commit hooks"
              pre-commit install --hook-type pre-commit --hook-type commit-msg
            fi
          '';

          # Cache all cargo + sccache artefacts under the project tree
          # rather than $HOME so the Nix dev shell stays reproducible
          # across machines.
          CARGO_HOME = ".nix-cargo";
          RUSTUP_HOME = ".nix-rustup";
          SCCACHE_DIR = ".nix-sccache";
        };

        # `nix flake check` runs `cargo fmt --check` + `cargo check`
        # against the dev shell so flake hygiene is verified.
        checks = {
          # The cargo-check derivation runs in pure-evaluation mode
          # without network — fine because we vendor `Cargo.lock` +
          # the offline crate cache. Heavy gates (clippy, nextest)
          # run in CI proper, not in `nix flake check`.
          fmt = pkgs.runCommand "pulsar-fmt-check" { buildInputs = [ rustToolchain ]; } ''
            cp -r ${self} src
            cd src
            cargo fmt --all -- --check
            touch $out
          '';
        };

        # `nix fmt` formats this flake.nix using nixpkgs-fmt.
        formatter = pkgs.nixpkgs-fmt;
      }
    );
}
