# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root (the domain glossary).
- **`DECISIONS.md`** at the repo root: this repo's ADR log. Decisions are recorded there as
  sections in one file, not as per-file ADRs under `docs/adr/`. Read the sections that touch
  the area you're about to work in. New decisions are appended to `DECISIONS.md`; do not
  create a `docs/adr/` directory.

If `CONTEXT.md` doesn't exist, **proceed silently**. Don't flag its absence; don't suggest
creating it upfront. The `/domain-modeling` skill creates it lazily when terms actually get
resolved.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis,
a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary
explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing
language the project doesn't use (reconsider) or there's a real gap (note it for
`/domain-modeling`).

## Flag decision conflicts

If your output contradicts a decision recorded in `DECISIONS.md`, surface it explicitly rather
than silently overriding:

> _Contradicts DECISIONS.md ("stateless transport, no SSE"), but worth reopening because…_
