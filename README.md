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
  objects/product.yaml
  forms/catalogue.yaml
  seeds/products.csv
  files/products/oslo.png
```

Write it in metadata-driven with `metadata:template:export`, and check it there with
`metadata:template:validate` before you open the pull request.

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

## Checking locally

You need PHP 8.4 and Composer, with
[`fedale/metadata-driven-template-format`](https://github.com/fedale/metadata-driven-template-format)
cloned next to this repository as `template-format`:

```bash
composer install
bin/catalog check --base=origin/main
```

## For maintainers

- Merge only what you reviewed: the check is structural, it cannot tell whether a template
  makes sense.
- Branch protection on `main`: pull requests only, the **Check** status required, a review from a
  code owner ([`.github/CODEOWNERS`](.github/CODEOWNERS)) required.
- The tools (`bin/`, `src/`, `tests/`) have their own tests: `composer qa`.

## License

The tools are under the [MIT license](LICENSE). Each template carries its own license, in its
manifest.
