# ADR-0074: A directive is template syntax only where the template is

## Status

Accepted. **Amends [ADR-0024](0024-templating-engine-and-design-system.md)**
(templating engine and design system) by narrowing where the trusted compiler expands
a directive: in the template's inline-HTML runs, and nowhere else. ADR-0024 named the
directive set and the dual execution model; both stand. What it never said — because
nobody had asked — is what `@name(...)` means when it appears _inside_ a `<?php ?>` or
`<?= ?>` region, and the compiler's answer was "a directive", which cannot be right.

Written as its own record rather than as an edit to ADR-0024 because
[ADR-0001](0001-ci-gates-and-adr-discipline.md) says ADRs are immutable records, and
because [ADR-0073](0073-a-directive-that-emits-a-field-nobody-reads-is-not-support.md)
had already established the annotate-and-supersede shape for this same document.

## Context

`TemplateCompiler::compileDirectives()` walked the whole template source with a regular
expression and replaced every `@name(...)` it found. A template is not uniformly
template syntax, and PHP already knows where the boundary is.

Two things go wrong when a directive is expanded inside an open PHP tag.

**It cannot parse.** Every directive compiles to a `<?php ... ?>` block. Expanding one
inside `<?= ... ?>` therefore produces

```
<?= <?php echo $translator->get('key'); ?> ?>
```

which is not PHP. The compiled file is written, cached, and fatals when it is included.

**`@` is already an operator there.** Inside a PHP region, `@` is the error-suppression
operator. `<?= @t('key') ?>` is valid PHP that calls a global `t()` helper with warnings
suppressed. It is not a template directive and never was; the compiler was rewriting
somebody's PHP.

The scale was not small. 23 template files carrying 318 `@name(...)` call sites inside
PHP tags had never compiled to parseable PHP — 9 of the forum extension's 18 admin views
among them. They were not "rarely rendered"; they were unrenderable, and had been since
the directive expansion was written.

## Decision

**Directive expansion is confined to the template's inline-HTML runs, and the boundary
is decided by PHP's own lexer.** The compiler tokenises the source with
`PhpToken::tokenize()` and expands directives only within `T_INLINE_HTML` tokens. Text
inside `<?php ?>`, `<?= ?>` or any other PHP region is passed through untouched.

The lexer is the arbiter rather than a hand-written scanner for the same reason the ADR
index is generated rather than typed: a second implementation of "where does PHP code
begin" is a second thing that can be wrong, and this one would be wrong in the direction
of silently corrupting a template. `PhpToken::tokenize()` is the definition, not an
approximation of it.

### What this is not

It is not a change to the directive set, to the escaping contexts, to the sandbox
bounds, or to the untrusted-template interpreter. A directive that was a directive in
markup is still a directive in markup, compiled to exactly what it compiled to before.

## Consequences

### Positive

- 23 templates and 318 call sites compile. All 159 `.pulse.php` templates in the
  repository now produce parseable PHP, which is asserted rather than assumed.
- `@` recovers its PHP meaning inside PHP regions, so a template author's own code is no
  longer rewritten underneath them.
- The rule is stateable in one sentence and needs no exception list.

### Negative

- **This is a behaviour change to the documented compiler contract.** Previously every
  `@name(...)` in the source was a directive regardless of context. A template that
  relied on a directive expanding inside a PHP tag stops working — but such a template
  has never compiled, so the set of affected working templates is empty by construction.
- Tokenising costs a pass over the source that the regular expression did not. It is
  paid at compile time, once per template, and cached; `view:compile` moves it to build
  time entirely.

### Neutral

- 318 sites are now written `<?= @t('key') ?>`: valid PHP calling the raw `t()` helper
  with suppression, rather than the `@t` directive, which escapes. Every parameterised
  one of them wraps its arguments in `$e(...)` except a single call passing an integer,
  so no injection path exists today. Rewriting them to markup-level `@t` is a separate
  change and is recorded as outstanding rather than done here.

## Links

- [ADR-0024](0024-templating-engine-and-design-system.md) — the amended record; its
  trusted-path directive set is what this narrows the scope of
- [ADR-0073](0073-a-directive-that-emits-a-field-nobody-reads-is-not-support.md) — the
  other amendment to ADR-0024, and the precedent for recording rather than editing
- [ADR-0001](0001-ci-gates-and-adr-discipline.md) — why this is a record and not an edit
- [ADR-0060](0060-a-check-never-observed-to-fail-is-indistinguishable-from-no-check.md)
  — why "all templates compile" is an assertion that runs and not a claim in prose
- `src/View/Engine/TemplateCompiler.php` — where the boundary is drawn
- [`docs/templating.md`](../templating.md) — the author-facing statement of the rule
