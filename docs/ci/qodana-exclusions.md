# Qodana exclusions — governance

> Companion to `qodana.yaml`. Each exclusion in `qodana.yaml` is documented
> here with the rationale and the path back to fully-enabled inspection.

## Why exclusions need governance

`qodana.yaml` runs with `failThreshold: 0` so CI never fails on Qodana output —
the static analyser is advisory at this stage of the project. Combined with
the broad exclusions catalogued below, this means a missed inspection is
indistinguishable from no inspection at all. The TOOL-GATE-02 audit finding
(external auditor) flagged this risk: each exclusion must have a documented reason and
a ratchet plan, so the surface shrinks over time instead of growing.

## Exclusion categories

### Category A — vendor / build artefacts (permanent)

- `vendor/**` — dependencies are inspected by their own maintainers; Qodana
  cannot resolve types without `composer install` anyway.

These exclusions are permanent and need no further review.

### Category B — PHP 8.5 false positives (Qodana / PhpStorm-2024 inspector lag)

- `PhpOverriddenMethodExistsInspection`
- `PhpParamsInspection`
- `PhpUndefinedClassInspection`
- `PhpUndefinedNamespaceInspection`

Without `vendor/` in CI, Qodana cannot resolve third-party classes, producing
thousands of false positives. PHPStan max + Psalm level 1 are the
authoritative type sources; Qodana is informational only for these.

**Ratchet plan:** re-evaluate after Qodana adds `composer install` support
to its native PHP analyser (tracked upstream).

### Category C — ESLint duplication

- `Eslint`, `EsLintInspection` — frontend lint runs via `pnpm lint` in the
  JS/TS CI job. Qodana's ESLint integration would double-report.

### Category D — Style / cosmetics (TODO ratchet)

Inspections currently excluded that should re-enable once codebase reaches
the relevant cleanliness threshold:

- `MagicNumberJS`, `NestedFunctionCallJS` — frontend style, low signal until
  the analytics tracker frontend is more consolidated.
- Various JS-flavour code-smell inspections listed in `qodana.yaml`.

**Ratchet plan:** re-enable one inspection per RC release after fixing the
top-10 offenders for that inspection.

## Adding a new exclusion

1. Add the inspection to `qodana.yaml` under the appropriate category.
2. Document the rationale in this file.
3. Note the ratchet condition (when can we remove it?).
4. Reference the audit finding or upstream ticket that motivated the exclusion.

Exclusions added without all three rules are subject to revert at the next
audit review.
