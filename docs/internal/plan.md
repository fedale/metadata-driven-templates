# Plan: the template catalog

The brief is in metadata-driven's `docs/internal/template-plan.md`, section "The catalog". This
file is the catalog's own plan: its phases and what was built.

Status: **C1 done**, awaiting review before C2 (publication on merge).

## Decisions

- **On GitHub, no server** (2026-11-06): a repository, pull requests, Actions, Pages, Releases.
- **Reviewed before listing** (2026-11-06): every version goes through a pull request a
  maintainer reviews; the automatic check runs first.
- **Sources, not archives**: a pull request adds `templates/<vendor>/<name>/<version>/`. The
  catalog packs it: archives are reproducible and Ed25519 signatures deterministic, so the whole
  index can be rebuilt from the sources at every publication, with no state.
- **20 MB per version** (decimal MB, files included).
- **Owners are GitHub user logins** (`vendors.yaml`): the check compares them with the pull
  request's author. Organizations and teams would need the GitHub API (membership); later.
- Repository: `fedale/metadata-driven-templates`; tools in PHP 8.4 (`Fedale\TemplateCatalog`,
  `symfony/console`, `symfony/process`), on `fedale/template-format`.

## Phases

1. **C1 — the repository and the check** — done. As built:
   - `vendors.yaml` (vendor → owners), `.github/CODEOWNERS` (`* @fedale`: a maintainer reviews
     every pull request; branch protection to set on GitHub).
   - `bin/catalog check --base= [--repository=] [--author=]` (`CheckCommand`,
     `PullRequestCheck`, `Git`, `Vendors`): under `templates/`, only new version directories;
     the published ones (read from the base with `git ls-tree`, not from the checkout, where an
     old directory and a new one look alike) never change, even by an added file; name and
     version patterns; the vendor registered **on the base** (a pull request cannot register a
     vendor and publish under it) and owned by the author; the format library's checks, located
     in the repository; manifest matching the directory; higher than every published version;
     size and symbolic links. Everything outside `templates/` is the maintainers' review.
   - `.github/workflows/check.yml`: `pull_request` (read-only token, no secrets), the tools
     from the **base** commit, the templates from the head: a pull request cannot change the
     checks it is checked by. A second job runs the tools' own `composer qa`. The format comes
     from `fedale/metadata-driven-template-format`, checked out beside the tools for the path
     repository.
   - Not checked yet: the semver bump against the changes (C4).
2. **C2 — publication on merge**: an Action on `push` to `main` packs each new version
   (`Archive::pack`), uploads it as a Release asset (tag `<vendor>-<name>-<version>`), rebuilds
   `index.json` from every version and `yanked.yaml`, signs each entry with the catalog key (a
   repository secret), and deploys `index.json` to GitHub Pages. metadata-driven's
   `TEMPLATE_CATALOG_URL` and `TEMPLATE_CATALOG_KEY` then point at it.
3. **C3 — the static site**: a page per template (README, objects and fields read from the
   documents, versions, requires, license), a search page, and a JSON search file; built by the
   same Action.
4. **C4 — the rest**: removal of a template (every version yanked), issue templates for
   reports, verified vendors, organizations as owners, the semver check against the previous
   version's items (the item comparison is in metadata-driven today: move it into the format
   library first), metadata-driven's phase 7 (`metadata:template:publish`).

## Not in scope

Paid or private templates (a private index covers them), comments and ratings, download counts
beyond the Releases' own.
