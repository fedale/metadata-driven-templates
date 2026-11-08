# metadata-driven templates

The central catalog of metadata-driven templates: ready-made models
(objects, forms, views, translations, seed data) that you install into a metadata-driven
instance and upgrade later, keeping your own changes.

This repository holds the sources of every published version. Publishing is a pull request,
and every pull request is checked automatically and then reviewed by a maintainer before
anything is listed.

## Publishing a template

### 1. Register your vendor

Template names are `vendor/name`, as Composer names packages: `acme/shop`. Your vendor
namespace (`acme`) belongs to the GitHub accounts listed for it in
[`vendors.yaml`](vendors.yaml):

```yaml
acme:
  owners: [mario-rossi, giulia-bianchi]
```

Open a pull request that adds your vendor, and nothing else. Once it's merged, you can publish
under it. Owners are GitHub user logins: organizations and teams aren't supported yet.

### 2. Add a version

A version is a directory, `templates/<vendor>/<name>/<version>/`, holding the template's source:
its manifest (`template.yaml`), its documents, and the CSV and files of its seeds.

```
templates/acme/shop/1.1.0/
  template.yaml
  README.md              # optional: shown on the template's page
  objects/product.yaml
  forms/catalogue.yaml
  seeds/products.csv
  files/products/oslo.png
```

Write it in metadata-driven with `metadata:template:export`, and check it there with
`metadata:template:validate` before you open the pull request.

A `README.md` at the root of the version is optional: the template's page shows it, in
GitHub-flavored Markdown (raw HTML is shown as text, `javascript:`, `vbscript:`, `file:` and
non-image `data:` links are dropped, 100 kB at most). It travels in the archive too.

The pull request must come from an owner of the vendor, and may only add version directories:

- **Published versions never change.** Installations compare against them when they upgrade:
  to fix something, publish a new version.
- **The version is higher** than every version already listed, and the directory matches the
  manifest's `id` and `version`.
- **The template is valid**: every document against its schema, ids, references, seeds and
  files, as metadata-driven checks them.
- **20 MB at most** per version, files of the seeds included. No symbolic links.

### 3. Review

The **Check** action runs on every pull request and lists each problem with the file it is in.
When it passes, a maintainer reviews the template and merges it.

### 4. Publication

Once merged, the **Publish** action packs every version, lists it in the signed index and
deploys them on GitHub Pages with the catalog's pages, within a few minutes: the home page
(every template, searchable), a page per template at `templates/<vendor>/<name>/` (its README,
objects and fields, forms, data, versions) and `search.json`, the same list for tools. Your
version is then installable:

```bash
bin/console metadata:template:install acme/shop@1.1.0
```

### Yanking a version

A broken version isn't deleted: installations rely on it to upgrade. Yank it instead, with a
pull request to [`yanked.yaml`](yanked.yaml):

```yaml
acme/shop: [1.1.0]
```

A yanked version is hidden from new installs and upgrades, and stays installable by its exact
version. Only an owner of the vendor can yank its versions, or list them again.

## Using the catalog in metadata-driven

Set these two variables in metadata-driven's environment:

```bash
TEMPLATE_CATALOG_URL=https://fedale.github.io/metadata-driven-templates/index.json
TEMPLATE_CATALOG_KEY=<the content of catalog.pub>
```

The catalog is always signed: metadata-driven refuses an entry whose signature doesn't match
the key.

## Checking locally

You need PHP 8.4 and Composer, with
[`fedale/metadata-driven-template-format`](https://github.com/fedale/metadata-driven-template-format)
cloned next to this repository as `template-format`:

```bash
composer install
bin/catalog check --base=origin/main
bin/catalog build --unsigned --output=/tmp/catalog-site   # the site, unsigned: to look at only
php -S 127.0.0.1:8000 -t /tmp/catalog-site                 # then open http://127.0.0.1:8000
```

## For maintainers

- Merge only what you reviewed: the check is structural, it cannot tell whether a template
  makes sense.
- **The catalog's key.** Generate it once, outside any repository, with metadata-driven:
  `bin/console metadata:template:keygen ~/catalog.key`. The public key it prints goes into
  [`catalog.pub`](catalog.pub), by pull request. The content of `~/catalog.key` goes into the
  `CATALOG_SECRET_KEY` secret of the `github-pages` environment (**Settings → Environments →
  github-pages**, deployments limited to `main`); keep a copy in a password manager, then delete
  the file. A lost or leaked key means a new one, and every installation updating
  `TEMPLATE_CATALOG_KEY`.
- **GitHub Pages**: **Settings → Pages → Build and deployment → Source: GitHub Actions**.
- The publication rebuilds everything at every merge: archives are reproducible and signatures
  deterministic, so unchanged versions keep their checksum and signature. It publishes nothing
  if a version on `main` is invalid. Rerun it from **Actions → Publish → Run workflow**.
- Branch protection on `main`: pull requests only, the **Check** status required, a review from a
  code owner ([`.github/CODEOWNERS`](.github/CODEOWNERS)) required.
- The tools (`bin/`, `src/`, `tests/`) have their own tests: `composer qa`.

## License

The tools are under the [MIT license](LICENSE). Each template carries its own license, in its
manifest.
