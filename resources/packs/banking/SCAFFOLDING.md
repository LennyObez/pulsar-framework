# Banking Control Pack — Controls Coverage

This pack provides scaffolding that **supports controls for** the following regulatory frameworks. It does **not** ensure, guarantee, or certify compliance with any regulation.

## PCI-DSS v4.0

| Requirement                        | Pack Support                                         | Status   |
| ---------------------------------- | ---------------------------------------------------- | -------- |
| Req 3: Protect stored account data | Encryption-at-rest configuration stub                | Scaffold |
| Req 7: Restrict access             | Role-based access configuration point                | Scaffold |
| Req 8: Identify users              | KYC profile entity with verification status tracking | Scaffold |
| Req 10: Log and monitor            | PCI-compliant logging configuration stub             | Scaffold |
| Req 12: Security policies          | Policy documentation templates                       | Scaffold |

## PSD2

| Requirement                | Pack Support                                   | Status   |
| -------------------------- | ---------------------------------------------- | -------- |
| Strong Customer Auth (SCA) | Authentication hook point in payment flow      | Scaffold |
| Transaction monitoring     | Transaction entity with audit trail            | Scaffold |
| Open Banking API readiness | API-first entity design with versioning fields | Scaffold |

## DORA (Digital Operational Resilience Act)

| Requirement            | Pack Support                               | Status   |
| ---------------------- | ------------------------------------------ | -------- |
| ICT risk management    | Audit trail configuration                  | Scaffold |
| Incident reporting     | Logging configuration with severity levels | Scaffold |
| Operational resilience | Health check and monitoring hook points    | Scaffold |

## Additional Steps Needed

The following steps are **required** for actual regulatory compliance and are **not** provided by this pack:

- Professional security audit by a Qualified Security Assessor (QSA)
- PCI-DSS Self-Assessment Questionnaire (SAQ) completion
- Penetration testing by an approved scanning vendor (ASV)
- Strong Customer Authentication (SCA) integration with a certified payment provider
- Incident response plan development and testing
- Staff security awareness training program
- Network segmentation and firewall configuration
- Regular vulnerability scanning and patch management
- Data retention and disposal procedures
- Business continuity and disaster recovery planning
