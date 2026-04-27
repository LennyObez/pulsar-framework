# pulsar-ssrf-guard

Server-Side Request Forgery (SSRF) protection: URL allowlisting + DNS-rebinding defence + cloud-IMDS lockout per AWS / Azure / GCP metadata-service threat models.

## What

Wraps `reqwest` (and any caller-provided HTTP client) with a pre-flight URL gate that:

* Resolves the URL's hostname via `hickory-resolver` and rejects any IP in the IANA-reserved ranges + the cloud Instance Metadata Service (IMDS) ranges (`169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`, `metadata.azure.com`).
* Re-resolves the hostname before connect to defeat DNS rebinding (resolved IP at gate must match resolved IP at connect).
* Enforces an allowlist of permitted egress hostnames per outbound-class.

## Why

SSRF is the OWASP Top 10:2021 #10 — server-side requests to internal/cloud-metadata endpoints have leaked production credentials in dozens of public incidents (Capital One 2019 IMDS leak being the canonical case). Pulsar gates every outbound HTTP call by default; downstream callers must whitelist allowed destinations rather than rely on perimeter firewalling.

## How

`pulsar-ssrf-guard` is a v2.3 dé-fusion of the v2.2 `pulsar-guard` meta-crate per Decision 2.51. The DNS-rebinding defence resolves the hostname twice (gate-time + connect-time) and rejects the request if the resolved IP changes — this defeats the classic rebinding attack where an attacker-controlled DNS server returns an allowlisted IP to the gate then a private IP to the connect.

**Status:** Placeholder release for namespace reservation. The implementation ships in `0.1.0` per Sprint 1.5. See [`docs/plan.md`](../../docs/plan.md) Section IV.11.6 + Section V.1.5.

## Licence

Apache-2.0. See the workspace [`LICENSE`](../../LICENSE) file. Trademark policy in [`docs/trademark-policy.md`](../../docs/trademark-policy.md).
