# Plan: the template catalog

The brief is in metadata-driven's `docs/internal/template-plan.md`, section "The catalog". This
file is the catalog's own plan: its phases and what was built.

Status: **C1 and C2 done** (C2 on `feature/publish`), awaiting review before C3 (the static
site).

## Decisions

- **On GitHub, no server** (2026-11-06): a repository, pull requests, Actions, Pages, Releases.
- **Reviewed before listing** (2026-11-06): every version goes through a pull request a
  maintainer reviews; the automatic check runs first.
- **Sources, not archives**: a pull request adds `templates/<vendor>/<name>/<version>/`. The
  catalog packs it: archives are reproducible and Ed25519 signatures deterministic, so the whole
  index can be rebuilt from the sources at every publication, with no state.
- **20 MB per version** (decimal MB, files included).
- **Archives on GitHub Pages, next to the index** (2026-11-06, C2), not in Releases: the whole
  site is rebuilt at every merge and deployed at once, so the index and the archives never
  disagree, and there are no releases to keep in step. Pages serves 1 GB at most: the build
  stops at 900 MB, and archives move to Releases then.
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
2. **C2 — publication on merge** — done. As built:
   - `bin/catalog build --output= [--repository=] [--unsigned]` (`BuildCommand`,
     `CatalogBuilder`): every version directory read, checked and matched against its path (a
     link anywhere refused, `Links`), `yanked.yaml` (`Yanked`) naming published versions only;
     then `archives/<vendor>/<name>-<version>.tar.gz`, `index.json` (relative urls, requires
     from the manifest, yanked flags) and `catalog.pub`. Nothing is written unless everything is
     valid. Each entry signed with `CATALOG_SECRET_KEY` (environment, never an argument) and
     verified against `catalog.pub`, so a wrong key fails the build rather than publishing an
     index every installation refuses. `--unsigned` to look at it locally only.
   - `.github/workflows/publish.yml`: on push to `main` and by hand; one job in the
     `github-pages` environment (its secret, deployments limited to `main`), `pages: write`,
     `id-token: write`; build, `upload-pages-artifact`, `deploy-pages`; one publication at a
     time, never cancelled.
   - The check now reads `yanked.yaml` too: valid, naming published versions, each yanked or
     listed again by an owner of its vendor (`BaseBranch` gathers what the check reads from the
     base: vendors, published versions, yanked versions).
   - Index address: `https://fedale.github.io/metadata-driven-templates/index.json`.
   - The format library is checked out at its `main`: pin it to a tag once it has one.
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
