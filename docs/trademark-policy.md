# Pulsar Framework — Trademark Policy

* **Owner:** Lenny Obez
* **Trademark:** "Pulsar Framework" + associated word-and-design marks
* **Primary registry:** European Union Intellectual Property Office (EUIPO), Class 9 + Class 42
* **International coverage (planned at GA):** Madrid Protocol extensions covering United Kingdom, United States, Switzerland, Canada, Australia, Singapore, Japan
* **Effective date:** Phase 0 filing (concurrent with `v0.0.1-alpha.0`); full registration target before `v0.5.0`
* **Authoritative ADRs:** [ADR-0005](adr/0005-apache-2-0-licence.md) (Apache-2.0 + EUIPO trademark)
* **Section II decision:** [Decision 2.21](plan.md) + [Decision 2.50](plan.md) (trademark + patent posture)

## 1. Purpose of this policy

Pulsar Framework is distributed under the [Apache License, Version 2.0](../LICENSE). Apache-2.0 grants broad rights to use, modify, and redistribute the code and explicitly excludes trademark rights (Section 6 of the licence: "This License does not grant permission to use the trade names, trademarks, service marks, or product names of the Licensor"). This document describes how the **"Pulsar Framework" name and associated marks** may be used by the community, by forks, by downstream applications, by commercial entities, and by service providers — independently of the code licence.

Two goals shape the policy:

1. **Protect users and downstream organisations from brand confusion.** Regulated downstream organisations (banking, healthcare, legal, government) evaluate framework provenance during procurement. Unrelated derivatives or low-quality forks using the same brand would dilute that provenance signal.
2. **Preserve community goodwill and openness.** The policy must remain compatible with the OSS spirit of the licence: forks are welcome, derivative work is welcome, reasonable references to the framework are welcome.

## 2. What is covered

The trademark covers:

* The literal text **"Pulsar Framework"** in any case combination.
* The word **"Pulsar"** when used in a context that refers to the framework (e.g. "I'm a Pulsar contributor", "the Pulsar runtime"). The unprefixed `pulsar` crate name on crates.io is **not** owned by this project (it is an Apache Pulsar — the messaging system — Rust client). The `pulsar-` prefix is the framework's namespace per [ADR-0006](adr/0006-crates-io-pulsar-namespace.md).
* Associated word-and-design marks: the project logo (when published), favicon, website wordmark.

The trademark does **not** cover:

* The Apache Pulsar messaging system or any of its derivatives. "Apache Pulsar" is a trademark of the Apache Software Foundation.
* Astronomical pulsars or any unrelated commercial use of the word "pulsar" in domains outside software frameworks (Class 9 + Class 42 — software).

## 3. What you may always do (no permission required)

The following uses are explicitly permitted without seeking permission:

* **Refer to Pulsar Framework by its name in factual descriptions.** "Built on Pulsar Framework", "compatible with Pulsar Framework 1.0", "tested against Pulsar Framework", "this article reviews Pulsar Framework's audit chain". Nominative use is always permitted.
* **State that you contribute to Pulsar Framework.** "Lenny Obez is the maintainer of Pulsar Framework", "Jane Doe contributed the OAuth 2.1 module to Pulsar Framework".
* **State that your fork is a fork.** "This is a fork of Pulsar Framework, originally created by Lenny Obez". Forks may use the name to identify their lineage. Forks may not use the name as their own product name (see Section 4).
* **Educational, journalistic, and analytical use.** Books, articles, blog posts, conference talks, podcasts, video tutorials, university courses — referring to Pulsar Framework as the subject of analysis is always permitted.
* **Use within Pulsar Framework's own ecosystem channels.** Authoring an extension under the official extension marketplace, contributing an ADR, opening an issue or pull request, posting in the official community channels — all use the name freely.
* **Comparative reviews.** "Pulsar Framework vs Axum: a benchmark comparison" — comparative use is permitted.

## 4. What requires permission

The following uses require explicit written permission from the trademark owner:

* **Using "Pulsar Framework" or "Pulsar" as the name of a derivative product or service.** A fork named "Pulsar Framework Plus", "MyPulsar", or "Pulsar Cloud" requires permission. Forks must adopt a distinct name (e.g. "MyFork built on Pulsar Framework").
* **Using the name in a commercial offering's marketing materials.** A SaaS product whose marketing prominently features the Pulsar name as an endorsement requires permission. This includes pay-for-managed-Pulsar offerings; a hosting provider running Pulsar Framework on behalf of customers is a "Pulsar-managed" service if that branding is used.
* **Using the project logo or wordmark.** Reproducing the logo on websites, in printed materials, on swag, or in commercial documentation requires permission and adherence to logo usage guidelines (forthcoming as the visual identity stabilises before `v1.0.0` GA).
* **Domain names.** Registering a domain that incorporates "pulsar-framework" or "pulsarframework" (e.g. `pulsar-framework.io`, `pulsarframework.cloud`) for a commercial service requires permission. Personal projects, blogs, and informational fan sites are not commercial.
* **Trademark application in any jurisdiction.** Filing a trademark application for "Pulsar Framework", "Pulsar", or any confusingly similar mark in any class is forbidden without prior written agreement.

## 5. Forks

Forks are welcome under the Apache-2.0 licence. The trademark policy applies as follows:

* The fork's **product name** must be distinct from "Pulsar Framework" or "Pulsar". Examples of acceptable fork names: "MyFork", "ContosoFork", "Quasar Framework" (already a Vue.js framework — picking a name not already owned is the contributor's responsibility), "EclipsedFramework". Examples of unacceptable names: "Pulsar Framework Plus", "Better Pulsar", "Pulsar Lite".
* The fork **may state in its README and documentation** that it is "a fork of Pulsar Framework, originally created by Lenny Obez, available at https://github.com/LennyObez/pulsar-framework". This is nominative use.
* The fork **may not use** the project logo, favicon, or wordmark (these are word-and-design marks).
* The fork **may not register** a confusingly similar trademark in any jurisdiction.
* The fork **may publish to crates.io** under any prefix that does not begin with `pulsar-` (the `pulsar-` namespace per [ADR-0006](adr/0006-crates-io-pulsar-namespace.md) is reserved for the official project; even though crates.io does not enforce namespaces, registering under `pulsar-` after the official namespace reservation lands at `v0.0.1-alpha.0` would be confusingly similar use).

## 6. Downstream applications

A downstream application is software built **on top of** Pulsar Framework as a library — not a fork. The trademark policy applies as follows:

* The downstream application's **product name** is its own choice. The application may say "built on Pulsar Framework" or "powered by Pulsar Framework" or "Pulsar Framework inside" without permission. Nominative use is permitted.
* The downstream application **may use** the official "Built on Pulsar" badge (forthcoming, with usage guidelines, before `v0.5.0` core release) on its website and documentation. The badge is provided as a courtesy to downstream adopters; its use is opt-in and does not imply endorsement by the framework.
* The downstream application's **product logo and visual identity are entirely the application's choice.** The framework does not impose visual identity on downstream apps.
* The downstream application **may not** present itself as official or endorsed by the framework. "Official Pulsar Framework healthcare app" is unacceptable; "healthcare app built on Pulsar Framework" is acceptable.

## 7. Commercial entities

Commercial entities (consulting firms, hosting providers, training providers, SaaS operators) using Pulsar Framework in revenue-generating activities are subject to the same nominative-use baseline as everyone else, with two clarifications:

* **Service offerings.** A commercial entity offering "Pulsar Framework consulting", "Pulsar Framework training", "Pulsar Framework support", or "Pulsar Framework managed hosting" is exercising nominative use of the framework name. This is permitted without permission, provided:
  * The entity does not present itself as an official Pulsar Framework partner unless a partnership agreement exists.
  * The entity does not use the project logo or wordmark in marketing without permission.
  * The entity makes clear that Pulsar Framework is a separate project owned by Lenny Obez.

* **Dual-licence path.** Per [Decision 2.49](plan.md), an optional commercial dual-licence path opens post-GA for organisations whose procurement requires a counterparty contract. The dual-licence is a parallel grant covering trademark usage, indemnification, and prioritised support — not a different code licence. Apache-2.0 remains the only OSS code licence. The dual-licence path is opt-in and not required to consume Pulsar Framework.

## 8. Patent posture (informational)

Per [Decision 2.50](plan.md), Pulsar Framework adopts a **defensive patent posture only**:

* The Apache-2.0 licence (Section 3) carries an explicit patent grant: every contributor automatically grants a perpetual, worldwide, non-exclusive, no-charge, royalty-free, irrevocable patent licence to make, use, sell, offer to sell, import, and otherwise transfer the work covering any patent claims they own that read on the contribution. The defensive termination clause activates if a licensee initiates patent litigation alleging that the work or a contribution infringes their patents.
* The maintainer commits in writing (this document and the forthcoming `docs/patent-non-aggression.md` published at GA) to a non-aggression pledge: **no offensive patent assertion against any user of Pulsar Framework, and no participation in patent troll arrangements.** The defensive Apache-2.0 termination clause is the sole patent enforcement mechanism.
* Pulsar Framework will **not** file software patents on its own technology (formally verified router trie, audit chain, capability table, etc.) and will **not** enforce any patent claims that arise as a side effect of contributors' grants.

## 9. Reporting trademark concerns

If you encounter:

* a third party using the "Pulsar Framework" name or logo in a way that appears to violate this policy,
* a trademark application filed by a third party for "Pulsar Framework" or a confusingly similar mark, or
* a domain registration that appears to be cybersquatting or phishing under the Pulsar Framework name,

please report it to `trademark@pulsar-framework.com` (active once the domain is provisioned) or open a private security advisory at `https://github.com/LennyObez/pulsar-framework/security/advisories/new` (which routes legal-hold-relevant reports through the same private intake as security disclosures).

## 10. Updates to this policy

This policy may be updated as the project matures (e.g. when the visual identity stabilises before `v0.5.0`, when the dual-licence path opens post-GA, when international trademark extensions land via Madrid Protocol). Material changes are announced in `CHANGELOG.md` under `[Unreleased]` and in the project release notes. Contributors and downstream organisations are not bound by retroactive policy changes; the policy in effect at the time of use governs.

## 11. Liaison and acknowledgements

This policy is modelled on the trademark policies of:

* The Linux Foundation Trademark Policy (linuxfoundation.org/trademark-usage)
* The Rust Foundation Trademark Policy (foundation.rust-lang.org/policies/logo-policy-and-media-guide)
* The PostgreSQL Brand Usage Policy (postgresql.org/about/policies/trademarks)
* The Apache Software Foundation Trademark Policy (apache.org/foundation/marks)

Trademark counsel: independent counsel for EUIPO filing engaged in Phase 0 (per Decision 2.50 timeline).

## 12. Contact

* Trademark inquiries: `trademark@pulsar-framework.com` (active once domain provisioned)
* Project maintainer: Lenny Obez via the GitHub repository [LennyObez/pulsar-framework](https://github.com/LennyObez/pulsar-framework)
* Security disclosures (separate channel): see [SECURITY.md](../SECURITY.md)
