<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

/**
 * EU AI Act (Regulation (EU) 2024/1689) requirements.
 *
 * This object deliberately implements none of the Has* interfaces, and that is
 * the finding rather than an omission.
 *
 * Those interfaces express duties over a deployment's SECURITY CONFIGURATION —
 * audit retention, encryption, breach deadlines, password shape. Every AI Act
 * article that would justify one of them sits in Chapter III, which governs
 * high-risk systems: Article 12 (logging), Article 15 (accuracy, robustness and
 * cybersecurity), Article 18 (ten-year retention of the technical documentation)
 * and Article 19 (six-month retention of automatically generated logs).
 *
 * Chapter III does not apply yet. The digital omnibus amending the Act, in force
 * since 27 July 2026, moved the Annex III use cases to 2 December 2027 and the
 * Annex I safety components to 2 August 2028. Asserting an Article 18
 * retention floor today would make an enabled framework ratchet an unrelated
 * control on the authority of an article that does not yet bind anyone, which is
 * the failure mode this codebase spent a release removing.
 *
 * What the Act does bind today it binds through conduct, not configuration:
 * Article 5 prohibited practices (2 February 2025), Article 4 AI literacy (same
 * date), Chapter V obligations on general-purpose model providers (2 August
 * 2025) and Article 50 transparency (2 August 2026 — untouched by the omnibus).
 * None of them is a retention period or a cipher choice. They are declared as
 * controls in AiActMapping, where a probe can observe them or an operator
 * artefact can discharge them.
 *
 * When Chapter III applies, this object gains the interfaces its articles then
 * justify. Not before.
 *
 * @internal
 */
final readonly class AiActRequirements {}
