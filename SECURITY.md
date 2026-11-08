# Security

## Reporting

Report privately, never in a public issue:

- a template meant to harm the people who install it, or holding data that should not be
  public;
- a weakness of the catalog: its checks, its tools, the signature of the index.

Use **Security → Report a vulnerability** on this repository
(<https://github.com/fedale/metadata-driven-templates/security/advisories/new>). The
maintainers answer there.

Anything else about a template (it does not install, its data is out of date) is a public
issue: **Issues → New issue → Report a template**.

## What happens to a reported template

A template that harms the people who install it is removed: `vendor/name: all` in
[`yanked.yaml`](yanked.yaml). New installations no longer find it and the catalog stops listing
it; its versions stay installable by their exact version, because installations rely on them to
upgrade. A version holding data that must not be published at all is taken out of the
repository's history by the maintainers, who then say so in the advisory.
