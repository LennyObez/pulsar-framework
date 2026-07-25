# PSD2 / eIDAS certificate validation

The `pulsar/psd2` extension validates the qualified certificates (QWAC / QSEAL)
that identify a payment service provider under PSD2 and eIDAS. Validation is
**fail-closed by design**: trust is derived only from the cryptographic eIDAS
chain and a live revocation check, never from self-declared certificate text.

## What `validate()` checks, in order

`CertificateValidatorInterface::validate($pem)` runs these gates. Any failed gate
throws `Psd2Exception` and no later gate runs:

1. **Parseable & unexpired** — the certificate parses and `now` is within its
   validity window.
2. **Trusted chain** — the certificate chains to a CA in the configured trust
   bundle (`openssl_x509_checkpurpose`). With **no** bundle configured the
   validator refuses every certificate rather than trusting attacker-suppliable
   strings.
3. **Not revoked** — a live OCSP check confirms the certificate has not been
   revoked (see below).
4. **PSD2 attributes** — roles, NCA name/id and qualified status are decoded
   from the ASN.1 `qcStatements` extension (ETSI TS 119 495), not string-matched
   against the certificate text.

## Revocation (OCSP, RFC 6960 / RFC 8954)

A certificate can chain to a trusted CA yet have been revoked since issuance, so
revocation is confirmed on every validation. The extension ships a
**self-contained OCSP client** — no dependency on the `openssl` command line:

- It builds a DER OCSP request for the leaf and POSTs it to the responder named
  in the leaf's Authority Information Access extension (through the framework's
  SSRF-protected HTTP client).
- The `BasicOCSPResponse` is trusted only after its signature verifies against
  **either** the issuing CA **or** a delegated responder whose certificate is
  (a) signed by that CA and (b) carries the `id-kp-OCSPSigning` extended key
  usage (§4.2.2.2).
- The echoed nonce (when present) must match the one sent, and the matching
  `SingleResponse` must be within its `thisUpdate` / `nextUpdate` window.

The check needs the **issuing CA certificate**, resolved from the trust bundle.
When the issuer cannot be resolved (a self-signed trust anchor, or an issuing CA
absent from the bundle) the check is skipped and audited — it never fabricates a
"good" verdict. To revocation-check leaf certificates, include their issuing CA
certificates in the trust bundle.

### Fail-closed vs. fail-open

| Revocation result                                                                            | Outcome                                                                                                                        |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Confirmed **revoked**                                                                        | Always rejected.                                                                                                               |
| Confirmed **good**                                                                           | Accepted.                                                                                                                      |
| **Inconclusive** (responder unreachable, no OCSP pointer, unparseable, signature unverified) | Rejected by default. Set `revocation_soft_fail = true` to allow through with an audit log when availability must be preferred. |

A confirmed revocation always rejects, regardless of `revocation_soft_fail`.

## Configuration

`config/psd2.php`, under `certificate`:

```php
'certificate' => [
    'require_qualified'       => true,   // require a qualified certificate
    'check_revocation'        => true,   // run the OCSP revocation check
    'revocation_soft_fail'    => false,  // false = reject on inconclusive; true = allow + audit
    'trusted_issuers'         => [],     // known NCA authorization numbers
    'trusted_ca_bundle_path'  => null,   // PEM bundle of trusted eIDAS QTSP CAs — REQUIRED to trust anything
    'validator'               => 'default',
],
```

`trusted_ca_bundle_path` is mandatory in practice: with it unset the validator
fails closed and rejects all certificates.

## Audit events

Every decision emits a security audit event, including:
`psd2_certificate_expired`, `psd2_certificate_untrusted_chain`,
`psd2_certificate_revoked`, `psd2_certificate_revocation_unverified`,
`psd2_certificate_revocation_soft_failed`, `psd2_certificate_revocation_skipped`,
and `psd2_certificate_validated`.

## Roadmap

OCSP is the primary revocation mechanism. A CRL (RFC 5280) fallback for issuers
that publish a CRL distribution point but no OCSP responder is planned as a
follow-up; the CRL distribution point is already decoded by `CertificateFields`.
