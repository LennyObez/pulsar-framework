# Healthcare Scaffolding Pack — Scaffolding Coverage

This pack provides scaffolding that **supports controls for** the following regulatory frameworks. It does **not** ensure, guarantee, or certify compliance with any regulation.

## HIPAA (Health Insurance Portability and Accountability Act)

| Rule                     | Requirement                        | Pack Support                           | Status   |
| ------------------------ | ---------------------------------- | -------------------------------------- | -------- |
| Privacy Rule             | PHI access controls                | Access logging configuration stub      | Scaffold |
| Privacy Rule             | Minimum necessary standard         | Data classification configuration      | Scaffold |
| Security Rule            | Access controls (164.312(a))       | Role-based access hook points          | Scaffold |
| Security Rule            | Audit controls (164.312(b))        | Audit trail configuration              | Scaffold |
| Security Rule            | Integrity (164.312(c))             | Data integrity verification hook point | Scaffold |
| Security Rule            | Transmission security (164.312(e)) | Encryption configuration stub          | Scaffold |
| Breach Notification Rule | Breach detection                   | Logging with severity classification   | Scaffold |

## MDR (Medical Device Regulation — EU 2017/745)

| Requirement                  | Pack Support                    | Status   |
| ---------------------------- | ------------------------------- | -------- |
| Software as Medical Device   | Classification hook point       | Scaffold |
| Clinical evaluation          | Data collection entity stubs    | Scaffold |
| Post-market surveillance     | Event logging configuration     | Scaffold |
| Unique Device Identification | Device identifier field support | Scaffold |

## HL7/FHIR Integration

| Capability               | Pack Support                             | Status   |
| ------------------------ | ---------------------------------------- | -------- |
| FHIR R4 resource mapping | Integration point stubs in entity design | Scaffold |
| HL7v2 message handling   | Message parser hook point                | Scaffold |
| Patient matching         | Configurable matching criteria           | Scaffold |

## Additional Steps Needed

The following steps are **required** for actual regulatory compliance and are **not** provided by this pack:

- HIPAA Security Risk Assessment conducted by a qualified professional
- Business Associate Agreements (BAAs) with all vendors handling PHI
- HIPAA Privacy Officer and Security Officer designation
- Workforce training on HIPAA requirements
- Physical safeguard implementation (facility access, workstation security)
- Incident response and breach notification procedures
- Regular compliance audits and penetration testing
- Documentation of all policies and procedures
- Patient rights implementation (access, amendment, accounting of disclosures)
- State-specific health data privacy law compliance review
- For MDR: CE marking process with a Notified Body
- For FHIR: Conformance testing against target FHIR Implementation Guides
