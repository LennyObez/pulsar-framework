# Getting help

Pulsar is maintained by one person, so the fastest way to get an answer is to make
it easy to give one. This page says where each kind of question belongs and what to
include.

## Pick the right place

| You want to                     | Go to                                                                                                                |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Ask how to do something         | [Discussions → Q&A](https://github.com/LennyObez/pulsar-framework/discussions)                                       |
| Report something broken         | [Bug report](https://github.com/LennyObez/pulsar-framework/issues/new?template=bug.yml)                              |
| Request a capability            | [Feature request](https://github.com/LennyObez/pulsar-framework/issues/new?template=feature.yml)                     |
| Propose an architectural change | [Architecture proposal](https://github.com/LennyObez/pulsar-framework/issues/new?template=architecture-proposal.yml) |
| Propose or discuss an extension | [Extension proposal](https://github.com/LennyObez/pulsar-framework/issues/new?template=extension-proposal.yml)       |
| Report a vulnerability          | **Not here — see [SECURITY.md](SECURITY.md)**                                                                        |

The distinction that matters most: **a question is not a bug.** "How do I bind a
service at a lower trust tier?" belongs in Discussions. "The container refuses a
binding the documentation says it accepts" is a bug. Filing the first as the second
is not a problem — it will simply be converted — but the second gets attention
faster because it comes with a reproduction.

## Read these first

They answer most questions, and they are kept current because gates fail when they
drift:

- [`README.md`](../README.md) — what Pulsar is and who it is for
- [`docs/install.md`](../docs/install.md) — installation and first run
- [`docs/`](../docs/) — the guides, by subsystem
- [`docs/adr/`](../docs/adr/) — **why** things are the way they are. If a design
  choice looks surprising, an ADR probably explains it, and reading it is usually
  faster than asking.
- [`docs/upgrade.md`](../docs/upgrade.md) — what changed between versions and what
  you must do about it

## What to include in a bug report

The issue template asks for these; here is why each one earns its place.

**The version, exactly.** `composer show pulsar/framework` or
`php bin/pulsar --version`. "Latest" is ambiguous during a release-candidate
series.

**A minimal reproduction.** The smallest code that shows the problem — ideally a
failing test. This is the single largest factor in how quickly something gets
fixed, because a reproduction turns a discussion into a red test.

**What you expected and what happened**, separately. The gap between them is often
where the real defect is, and sometimes it turns out to be a documentation defect
rather than a code one.

**Your environment**: PHP version, operating system, loaded extensions if relevant
(`php -m`), and the driver in play for a database issue. Several past defects were
platform-specific — one was a non-blocking stream call that silently does nothing
on Windows — so this is not boilerplate.

**Not required**: a fix. A well-described problem is a complete contribution.

## Response expectations

Set honestly rather than optimistically:

- **Vulnerability reports** are the priority and are acknowledged first. See
  [`SECURITY.md`](SECURITY.md) for that channel and its timelines.
- **Bug reports** with a reproduction get looked at soonest.
- **Questions and feature requests** are answered as capacity allows.
- **Large unsolicited pull requests** may wait, and may be declined on design
  grounds. Open an issue first — [`CONTRIBUTING.md`](CONTRIBUTING.md) explains
  what a change needs to be accepted, including the architecture decision record
  that a core change requires.

There is no service-level agreement here and it would be dishonest to imply one.
If something is urgent for a production system, say so in the issue and explain the
impact.

## Commercial support

None is offered today. If that changes, it will be stated here rather than
implied elsewhere.
