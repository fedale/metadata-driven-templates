# Plan: the template catalog

The brief is in metadata-driven's `docs/internal/template-plan.md`, section "The catalog". This
file is the catalog's own plan: its phases and what was built.

Status: **C1, C2 and C3 done**; C4 in steps (metadata-driven's `template-plan.md`, C4): the
semver check on `feature/semver-check`, awaiting review.

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
- **Owners are GitHub logins** (`vendors.yaml`): the check compares them with the pull
  request's author; organizations since 2026-11-08, through their public members (C4).
  Teams are not supported.
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
   - The semver bump against the changes: see C4.
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
   - The format library comes from Packagist (`^1.1`) since 2026-11-08, locked by
     `composer.lock`; the check job still checks it out beside the tools, for a base older
     than that (drop it once no open pull request has such a base).
3. **C3 — the static site** — done (2026-11-08). As built:
   - `SiteBuilder` (`src/Site/`), called by `CatalogBuilder::build()` once the index and the
     archives are written, from the same checked packages: `index.html` (every template, a
     search box filtering the list in the browser; without JavaScript the list stays whole, and
     how to point an instance at the catalog), `templates/<vendor>/<name>/index.html` and
     `search.json` (name, label, description, version, license, objects, url).
   - `TemplatePage`: a template's page shows its **current version**, the newest not yanked
     (the newest of all when every one is), as an install without a version takes it: label,
     description, license, languages, the install command, the README, objects and fields (a
     relation names its target by its plural label), composite forms, seeds (records counted,
     managed or once), then every version newest first (yanked flag, requires, archive and
     sha256).
   - **Authors' text is untrusted**: Twig with HTML autoescape and strict variables
     (`resources/site/`); the optional `README.md` of a version through league/commonmark
     (GitHub-flavored) with raw HTML escaped and unsafe links dropped, 100 kB read at most.
     Inline CSS (light and dark) and script, no external resource.
   - Relative links only (the site lives under `/metadata-driven-templates/`); the absolute
     address appears once, in the instructions, from `--site-url` (default
     `CatalogBuilder::SITE_URL`). No date in the pages: the same sources, the same site.
   - No per-version pages: older versions are listed with their archives only.
4. **C4 — the rest**, in steps, each reviewed:
   - **The semver check** — done (2026-11-08, `feature/semver-check`): `PullRequestCheck`
     compares each added version with the highest version listed for its template, read from
     the checkout (a pull request cannot change it, checked first), through the format's
     `Versions\ItemStates` and `Versions\VersionCheck` (v1.1.0), the rules an upgrade applies;
     migration steps count when the manifest has some for a version after the listed one. Run
     only once the version is otherwise valid and higher. Versions added together, with none
     listed before, are not compared with each other.
   - **Removal and reports** — done (2026-11-08, `feature/template-removal`, branched from
     `feature/semver-check`): `vendor/name: all` in `yanked.yaml` (`Yanked::ALL`,
     `isRemoved()`, a change of it is `[name, "all"]` in `differences()`). The index flags
     every version yanked (signed); the home page and `search.json` leave the template out, its
     page stays, marked removed, with the install command naming a version. The check: only an
     owner removes or lists again, a published template only; a removed template takes no new
     version. Reports: `.github/ISSUE_TEMPLATE/report-template.yml` (an issue form), and
     `SECURITY.md` with a contact link for private reports (GitHub's private vulnerability
     reporting, to enable in the repository's settings).
   - **Verified vendors and organizations** — done (2026-11-08, `feature/vendors`):
     `verified: true` in `vendors.yaml` (`Vendors::isVerified()`, unknown settings refused); a
     change of it (`verificationChanges()`) only from a maintainer, the users of the base's
     `.github/CODEOWNERS` (`Owners\Maintainers`, teams left out); a change of `vendors.yaml`
     must also leave it valid. The site shows a *verified vendor* badge, `search.json` a
     `verified` flag. Owners may be organizations: an author not listed owns the vendor when
     a public member of a listed owner, asked through `GET
     /orgs/{org}/public_members/{login}` (`Owners\GitHubOrganizationMembers`, the Action's
     read-only `GITHUB_TOKEN` against the rate limit, 204 yes, 404 no, anything else a problem
     "Run the check again", never a guess).
   - metadata-driven's phase 7 (`metadata:template:publish`).

## Not in scope

Paid or private templates (a private index covers them), comments and ratings, download counts
beyond the Releases' own.
