# Security Policy

## Our Commitment

Security is a core design goal of Pulsar. We prioritize secure defaults, auditability, and minimizing foot-guns.

> Note: "Compliance" depends on system implementation and operations.
> Pulsar provides features and documentation that map capabilities to common compliance controls, but does not certify a system by itself.

## Supported Versions

Pulsar is currently in release candidate (1.0.0-rc.x).

Security fixes are provided for:

- `main` branch (latest commit)
- Latest tagged `1.0.0-rc.*` release

After 1.0.0 GA, this policy will be expanded with an explicit support matrix and end-of-life timelines.

## Reporting a Vulnerability

Prefer **GitHub Private Vulnerability Reporting** (Security Advisories) if enabled on this repository. Otherwise, report privately via email.

- Email: pulsar+security@lennyobez.com
- Subject: `[SECURITY] <short summary>`
- Include:
  - affected version/commit
  - impact and attack scenario
  - reproduction steps or PoC (safe and minimal)
  - any mitigations you're aware of

**Do not include real secrets, PII, PHI, or PCI data in reports.** Use synthetic/redacted values only.

### Response targets

- Acknowledgment: within 48 hours
- Initial triage: within 7 days
- Fix timeline: depends on severity and complexity

### Severity classification

| Severity     | Description                                                                     | Examples                                                                               |
| ------------ | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| **Critical** | Remote code execution, authentication bypass, data exfiltration without auth    | Deserialization RCE, SQL injection in auth, broken crypto primitives                   |
| **High**     | Privilege escalation, significant data exposure, CSRF on state-changing actions | IDOR on sensitive resources, session fixation, missing access control                  |
| **Medium**   | Limited impact requiring specific conditions or user interaction                | Stored XSS in admin panel, SSRF with limited reach, timing side-channels               |
| **Low**      | Minor issues, information disclosure with minimal impact                        | Verbose error messages in production, missing security headers on non-sensitive routes |

Critical and High issues are prioritized for immediate patching. Medium and Low issues are addressed in the next scheduled release unless the risk profile changes.

## Coordinated disclosure

We follow coordinated disclosure.
Please do not publish details until a fix is available, unless we explicitly agree otherwise.

## Security guidelines for contributors

- Never commit secrets, private keys, or real customer data.
- Avoid adding new cryptography unless explicitly approved (prefer vetted primitives and well-reviewed designs).
- Security-relevant changes require:
  - tests
  - documentation updates
  - clear threat model notes when applicable
