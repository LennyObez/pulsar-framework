# Security Policy

## Our Commitment

Security is a core design goal of Pulsar. We prioritize secure defaults, auditability, and minimizing foot-guns.

> Note: “Compliance” depends on system implementation and operations.
> Pulsar provides features and documentation that map capabilities to common compliance controls, but does not certify a system by itself.

## Supported Versions

Pulsar is currently pre-alpha (0.x).

- Until `1.0.0`, security fixes are provided for:
  - the latest commit on the `main` branch
  - the latest tagged pre-release (if any)

After `1.0.0`, this policy will be expanded with an explicit support matrix and timelines.

## Reporting a Vulnerability

Please report security issues privately.

- Email: pulsar+security@lennyobez.com
- Subject: `[SECURITY] <short summary>`
- Include:
  - affected version/commit
  - impact and attack scenario
  - reproduction steps or PoC (safe and minimal)
  - any mitigations you’re aware of

### Response targets

- Acknowledgment: within 48 hours
- Initial triage: within 7 days
- Fix timeline: depends on severity and complexity

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
