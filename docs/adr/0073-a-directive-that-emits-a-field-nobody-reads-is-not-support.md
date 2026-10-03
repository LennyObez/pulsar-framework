# ADR-0073: A directive that emits a field nobody reads is not support

## Status

Accepted. **Amends [ADR-0024](0024-templating-engine-and-design-system.md)**
(templating engine and design system) by removing one entry from its trusted-path
directive list: `@method`. Every other decision in ADR-0024 stands exactly as written —
the dual execution model, the sandbox bounds, the escaping contexts, the design token
system and the `@php` audit trail are untouched. Deletes one `#[Internal]` class
(`MethodDirective`), narrows `@form`'s accepted `method` option to the two verbs an HTML
form can send, and adds one test (`tests/Unit/View/Directive/NoMethodSpoofingTest.php`).
Adds nothing to, and removes nothing from, the `#[Api]` surface.

Written as a record rather than as an edit to ADR-0024 because
[ADR-0001](0001-ci-gates-and-adr-discipline.md) says so: "ADRs are immutable records —
superseded decisions reference their replacement rather than being deleted." The first
attempt at this change deleted `@method` from ADR-0024's Decision list in place and
explained the deletion in the same sentence, which left the series with no record that
the framework ever shipped the directive.

## Context

ADR-0024's Decision named the trusted path's directive set, `@method` among them.
`MethodDirective` compiled it to a hidden field carrying the verb as
`<input type="hidden" name="_method" value="...">`.

Nothing on the server has ever read that field.

`Kernel::dispatchRoute()` takes the verb from the request line through the `Method` enum
and answers `501` for anything outside it. No file in `src/Http` or `src/Routing` reads a
`_method` body parameter or an `X-HTTP-Method-Override` header. That is not an accident
of the implementation: it is a property the framework claims in
[`docs/security/asvs-l2-matrix.md`](../security/asvs-l2-matrix.md) under ASVS V14.5,
which is the row an assessor reads to conclude that this framework has no
verb-tunnelling path into it.

So the directive produced a field with no reader, and the framework documented the
absence of the reader as a security property. Both statements were true at once, and the
template author was the only person standing between them.

### What the author was actually told

A form written the way ADR-0024's directive list implied — `@csrf` and `@method('DELETE')`
inside a `method="POST"` form — sends `POST`. Two outcomes follow, and neither is the one
on the page:

- A route registered for `DELETE` alone answers `405`. The author reads the directive,
  reads the 405, and has no reason to connect them.
- A path that also accepts `POST` runs the **POST** handler — under whatever
  authorization declaration that handler carries, which per
  [ADR-0049](0049-a-route-states-who-may-reach-it.md) is a per-route statement and not a
  per-path one. The destructive intent was written in the template and evaluated nowhere.

`@form` had the same defect one layer up. Given `['method' => 'DELETE']` it emitted a
`POST` form plus the hidden field, so it silently rewrote the verb and told the author it
had not.

### Why "just read the field" is not the cheap fix

Honouring `_method` is the standard framework answer, and it is a security decision, not
a convenience one. It makes the effective verb a function of the request **body**, which
means: a CSRF token scoped to a POST now authorises a DELETE; any middleware or proxy
rule that distinguishes verbs is deciding on the request line while the application
decides on the body; and request-smuggling analysis has to account for two disagreeing
sources of the same fact. That deserves its own record, taken deliberately. It does not
deserve to be implied by a directive that shipped without one.

## Decision drivers

1. A documented security property and a shipped directive must not contradict each other.
   One of the two has to go, and the property is the one an assessor relies on.
2. A silent wrong answer is worse than a missing feature. `@method` did not fail; it
   produced markup that read as a `DELETE` and behaved as a `POST`.
3. Whichever way this went, the change belongs in the record series. ADR-0024 named the
   directive, so ADR-0024 is where a reader will go looking for it.

## Decision

**`@method` is removed, and no built-in directive may compile to a method-spoofing
field.**

`MethodDirective` is deleted and unregistered. `DirectiveRegistry::registerBuiltins()`
carries the reason at the point in the file where a future author would add it back.

**`@form` refuses a verb an HTML form cannot send.** A `method` option of `PUT`, `PATCH`
or `DELETE` throws `ViewException::invalidDirective()` at render time, naming what would
otherwise have happened — that the form would have POSTed — and pointing at the two real
options: a route registered for `POST`, or `fetch()` with the real verb. It no longer
silently substitutes `POST` on the tag.

**Nothing on the server changes.** No reader for `_method` is added, and the ASVS V14.5
row keeps saying what it said.

**The rule is pinned by a test.** `NoMethodSpoofingTest` builds the real registry,
compiles every built-in directive with a verb argument, and asserts that none of them
emits the rendered `_method` field. It matches the rendered attribute rather than the
substring, because `@form`'s generated code legitimately holds a `$__form_method` local
and matching that would make the assertion unfalsifiable noise — which is
[ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)'s
subject arriving as a lazy pattern rather than as a missing test.

The same test scans every shipped tree for the field typed by hand, because the directive
was only one spelling of it. That scan reaches this record, which quotes the input the
directive compiled to two sections above — so it reads Markdown in two parts: an inline
code span outside a fenced block is a citation and is skipped, and everything else in the
file, fenced blocks and bare prose alike, is markup a reader can copy and is scanned. The
carve-out is that narrow on purpose. Excluding `docs/` wholesale would have been one line
and would also have excused a future page that hands an author the field in a code block,
which is the defect this record exists to close. A record that cannot quote what it
removed is a worse artefact than the directive it retired; a record that can hide a
working example behind the same rule would be worse still.

## Alternatives considered

### Read `_method` on the server and keep the directive

Rejected here, not forever. It is a coherent design and most of the ecosystem has chosen
it. It is also a change to how the effective HTTP verb is determined, which touches CSRF
scope, proxy and WAF rules that filter on the request line, and the smuggling surface —
and it would retract a published ASVS claim. That is a decision with its own context,
drivers and alternatives, so it gets its own record if anybody wants it, and it does not
arrive as a side effect of keeping a directive whose behaviour nobody had checked.

### Keep the directive and document that it does nothing

Rejected. The directive's entire output is a hidden input whose only purpose is to be
read. Documenting that it is never read leaves working code in the tree whose reason to
exist is a paragraph saying it has none, and the next author to see `@method` in the
registry will wire the reader rather than read the paragraph.

### Make `@form` substitute `POST` and warn in the log

Rejected. The author is not reading the log while writing a template, and the failure is
silent precisely where the consequence is destructive. A render-time refusal is read by
the person who can fix it, at the moment they can fix it.

### Amend ADR-0024 in place

Rejected, and this record is the correction of that attempt. See Status.

## Consequences

### Positive

- **The security matrix and the template language agree.** ASVS V14.5 is now a property
  of the whole framework rather than of everything except one directive.
- **`@form` fails where it used to lie.** A template asking for `DELETE` gets a message
  naming the two things that actually work, instead of markup that reports a verb it did
  not send.
- **The prohibition survives the next author.** `NoMethodSpoofingTest` runs over the real
  registry, so a re-added directive fails the suite by name rather than by review.

### Negative

- **A template still using `@method` renders the literal text.**
  `TemplateCompiler::compileDirectivesInMarkup()` emits an unregistered `@name` as-is, so
  `@method('DELETE')` now appears in the page source. That is loud and it is ugly, and it
  is the honest signal: nothing pretends the directive worked. It is still a visible
  regression for anybody upgrading a template that had it.
- **`@form` throws where it used to render.** A deployment passing `['method' => 'PUT']`
  gets an exception on the next render instead of a form that quietly POSTed. The old
  behaviour was wrong, but the failure is new and it is at render time.
- **Pulsar is now the framework without `@method`.** Developers arriving from Blade will
  look for it, not find it, and have to read why. `docs/templating.md` answers that
  question where they will ask it.

### Neutral

- **No `#[Api]` change.** `MethodDirective` was `#[Internal]`; the compiled output was
  never part of the stable surface.
- **No runtime behaviour changes on the server.** The verb has always come off the
  request line; this record only stops one directive from implying otherwise.

## Security impact

Removes a contradiction between the shipped template language and a published ASVS L2
claim. The concrete exposure being closed is authorization, not injection: a form whose
markup declared `DELETE` reached whatever handler was registered for `POST` on that path,
under that handler's access declaration rather than the one the author was aiming at.
Nothing about the request pipeline is loosened, no reader for `_method` or
`X-HTTP-Method-Override` is introduced, and the attack surface is strictly smaller by one
piece of markup the framework will no longer generate.

## Performance impact

None. One directive fewer in the registry; no hot path is touched.

## Migration / rollback plan

**Adoption.** Remove `@method(...)` from templates. To reach a route registered for
`PUT`, `PATCH` or `DELETE`, send the request with `fetch()` — [`docs/templating.md`](../templating.md)
carries the worked example under "HTTP methods in forms". To keep an HTML form, register
the route for `POST`. Replace a `@form` call carrying a `DELETE` method option the same
way; it now throws rather than substituting.

**Rollback.** Restoring `MethodDirective` and re-registering it brings the directive
back, and `NoMethodSpoofingTest` fails until it is deleted — deliberately. Rolling this
back means deciding to ship the field again, which is the decision this record declined,
so the test is the thing that makes the rollback argue for itself.

## Links

- [ADR-0024](0024-templating-engine-and-design-system.md) — the amended record; its
  trusted-path directive list is where `@method` was named
- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — why this is a record and not an edit
- [ADR-0049](0049-a-route-states-who-may-reach-it.md) — why reaching the POST handler
  instead of the DELETE handler is an authorization question
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md) —
  why the prohibition is a test
- [`docs/security/asvs-l2-matrix.md`](../security/asvs-l2-matrix.md) — the ASVS V14.5 row
  this record makes true of the whole framework
- [`docs/templating.md`](../templating.md) — the replacement guidance form authors read
- `src/View/Directive/DirectiveRegistry.php` — where the absence is explained
- `tests/Unit/View/Directive/NoMethodSpoofingTest.php` — the guard
