# Archived security documents

**Nothing in this directory is current policy. Do not act on it.**

These are dated snapshots: a review or a threat model as it stood on the day it was
written, against the code that existed then. They are kept because a security document is
evidence in its own right — an assessor may reasonably ask what was reviewed, when, and
what it concluded — and deleting one erases that record. They are kept **here**, under a
path whose name says what they are, because the alternative is a reader finding a
pre-release snapshot in `docs/security/` and taking it for the current position.

For the current position, read the live documents:

| Question                                      | Where the current answer lives                                                   |
| --------------------------------------------- | -------------------------------------------------------------------------------- |
| What does the WebAuthn implementation do?     | [`docs/webauthn.md`](../../webauthn.md)                                          |
| What does the OAuth2/OIDC server do?          | [`docs/oauth2-oidc.md`](../../oauth2-oidc.md)                                    |
| Which ASVS L2 clauses are covered?            | [`docs/security/asvs-l2-matrix.md`](../asvs-l2-matrix.md)                        |
| What does the framework offer per regulation? | [`docs/compliance.md`](../../compliance.md)                                      |
| What does **my deployment** achieve?          | `php bin/pulsar compliance:report` — computed from probes, never from a document |
| Why is the auth implementation homegrown?     | [ADR-0032](../../adr/0032-homegrown-auth-with-conformance-vectors-gate.md)       |

## What is here, and why it is no longer current

### `oauth2-webauthn-compliance-review.md`

A legal and compliance gap analysis dated **2026-02-16**, headed "Pre-release review
(implementations in progress)", scoped to "Extensions `pulsar/oauth2` and `pulsar/webauthn`
(rc.11 contract surface)".

Neither of those extensions exists. Both were merged into the bundled `pulsar/auth`
extension before 1.0.0, and `extensions/auth/pulsar.json` records that in its `replaces`
list. The review therefore assesses a package layout the tree no longer has, and it says so
about itself: it was a review of contracts, taken while the implementations behind them were
still being written.

### `oauth2-webauthn-threat-model.md`

A STRIDE-per-element threat model for the same two extensions. Its mitigations cite
`LeagueAuthorizationServer`, a class that does not exist anywhere in the repository — the
authorization server was rewritten as a homegrown implementation under
[ADR-0032](../../adr/0032-homegrown-auth-with-conformance-vectors-gate.md), which supersedes
ADR-0025 and ADR-0030. Every `file:line` citation in it points into code that has since been
replaced, so a reader following one lands somewhere else or nowhere at all.

The threat _analysis_ — the attack vectors, the STRIDE decomposition, the residual-risk
reasoning — is the part worth keeping and the reason this file was archived rather than
deleted. The _verification_ half of it has expired.

## Adding to this directory

Move a document here when it has become a record of what was true rather than a statement
of what is. Leave its body as it was written — an archived document that has been quietly
corrected is no longer evidence of anything — and add a banner at the top saying it is
archived, what it covered, and where the current answer lives.
