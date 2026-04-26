# Security Policy

## Supported Versions

Pulsar Framework is under active pre-1.0 development. Security patches are issued only for the latest minor release.

| Version | Security patches |
| ------- | ---------------- |
| 0.x (pre-alpha) | Only on `develop` and `main` branches; no backports to older alphas |
| 1.x (future GA) | Latest minor plus previous two for LTS (policy finalized at GA) |

## Reporting a Vulnerability

**Primary channel — GitHub Security Advisories (preferred):**

Open a private advisory at:

```
https://github.com/LennyObez/pulsar-framework/security/advisories/new
```

GitHub Security Advisories provide a private workspace for coordinated disclosure, patch development, and CVE assignment.

**Fallback channel — Email:**

If GitHub access is unavailable or the report must reach the maintainer by another path, send details to:

```
security@pulsar-framework.com
```

PGP encryption is supported. The public key fingerprint is published at `https://pulsar-framework.com/.well-known/security-contact.asc` (available once the domain is live).

## Disclosure Process

1. **Triage**: an acknowledgement is sent within **72 hours** of receipt.
2. **Assessment**: severity is evaluated per [CVSS 4.0](https://www.first.org/cvss/v4-0/). Severity levels follow the standard scale: Critical / High / Medium / Low / None.
3. **Remediation**: a fix is developed in a private branch. Target timelines:
   - Critical: patch within 14 days
   - High: patch within 30 days
   - Medium / Low: next scheduled minor release
4. **Coordinated disclosure**: the reporter is kept informed. A CVE is requested when applicable.
5. **Public advisory**: published once the patch is released, with credit to the reporter unless anonymity is requested.

## Scope

**In scope** (the framework itself):

- `crates/pulsar-*` under this repository
- Official Docker images
- Build artefacts published to crates.io under the `pulsar-*` namespace
- Default configuration examples shipped with the framework
- Documentation that, if wrong, could lead to an insecure deployment (e.g. outdated crypto primitives in the book)

**Out of scope**:

- Third-party extensions published outside the official `pulsar-*` namespace
- User-written application code built on Pulsar Framework
- Infrastructure of sites hosted under `pulsar-framework.com` unrelated to the framework release artefacts
- Denial-of-service reports that require more than a reasonable attacker budget (for example, brute-force floods larger than 10 Gbit/s)
- Reports that only apply to non-default, clearly documented insecure configurations

## Safe Harbor

Security researchers acting in good faith under this policy are granted safe harbor:

- No legal action will be pursued for vulnerability research conducted in accordance with this policy.
- Responsible disclosure is expected: a minimum embargo of **90 days** from first report, extendable by mutual agreement for complex fixes.
- Publishing proof-of-concept exploits before the embargo expires is considered a breach of this safe harbor.

## Rewards

Pulsar Framework does not currently operate a paid bug bounty program. Recognition is provided via:

- Public acknowledgement in the security advisory (with consent)
- Listing in the `docs/security/acknowledgements.md` hall of fame
- A permanent contributor role on relevant community channels

A formal bug bounty program may be launched after the 1.0.0 release.

## Cryptographic Primitives

The framework relies exclusively on audited cryptographic primitives provided by `ring`, `rustls`, and the standard Rust cryptography ecosystem. No custom primitives are shipped. Constant-time operations are enforced via the `subtle` crate, memory zeroing via `zeroize`, and secret wrapping via `secrecy`.

**Post-quantum cryptography** ships at 1.0.0 GA per plan Section 14.1: hybrid X25519 + ML-KEM (Kyber) for TLS 1.3 key exchange, Ed25519 + ML-DSA (Dilithium) for code-signing and token-signing. Classical-only variants are deprecated at 2.0.

**FIPS 140-3 validation** ships at 1.0.0 GA via opt-in build profile `fips` per plan Section 14.2 and Sprint 4.7: every cryptographic operation routes through a FIPS-140-3-validated backend (BoringCrypto, AWS-LC, or a FIPS-validated `ring` variant once available). Accredited laboratory targets Security Level 2 for the crypto module.

**HSM integration** ships at 1.0.0 GA via PKCS#11 adapter (`cryptoki`) per plan Section 14.3 and Sprint 2C.3: Thales Luna, AWS CloudHSM, Azure Dedicated HSM, Google Cloud HSM, Nitrokey HSM 2, YubiHSM 2 supported at launch; SoftHSM as the development reference backend. With HSM configured, master-key material never touches host process memory.

**Confidential computing** ships at 1.0.0 GA per plan Section 14.4 and Sprint 4.8: AMD SEV-SNP and Intel TDX confidential VMs with remote attestation at startup; integrates with Google Confidential Space and AWS Nitro Enclaves.

Side-channel hardening at the hardware level (Spectre/Meltdown-class attacks) is a documented non-goal for the v1.0.0 scope and is tracked in plan risk R-007 for post-GA 1.1; the framework relies on `subtle` for software-level constant-time primitives.

## Supply Chain

- Daily `cargo audit` run via CI workflow (`.github/workflows/audit.yml`)
- `cargo-deny` gates every build for license, CVE, and duplicate-version violations
- Release artefacts are signed via GitHub OIDC through the Trusted Publisher workflow; provenance is attached as SLSA metadata
- No vendored dependencies at build time; all dependencies pulled from crates.io with pinned checksums in `Cargo.lock`
