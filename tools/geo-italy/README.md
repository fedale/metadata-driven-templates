# fedale/geo-italy: building its seeds

The records of [`fedale/geo-italy`](../../templates/fedale/geo-italy) (regions, provinces,
municipalities) come from ISTAT's
[Elenco dei comuni italiani](https://www.istat.it/classificazione/codici-dei-comuni-delle-province-e-delle-regioni/),
CC BY 4.0. `build-seeds.php` turns that file into the template's three CSV files.

## A new version, when ISTAT publishes a new list

```bash
curl -L -o /tmp/comuni.csv https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.csv

# copy the latest version, then rebuild its seeds
cp -r templates/fedale/geo-italy/1.0.0 /tmp/geo-italy
php tools/geo-italy/build-seeds.php /tmp/comuni.csv /tmp/geo-italy/seeds
git diff --no-index templates/fedale/geo-italy/1.0.0/seeds /tmp/geo-italy/seeds   # what ISTAT changed
```

Then in `/tmp/geo-italy/template.yaml`: the new `version`, and the date in the description
("as downloaded on …"). The records are `managed` seeds: an installation's upgrade creates the
new municipalities, updates the changed ones and deletes the ones ISTAT merged away (records
are not counted by the version rules, so a data-only version may be a patch). Publish it from
metadata-driven:

```bash
bin/console metadata:template:publish /tmp/geo-italy --catalog=../metadata-driven-templates
```

The script stops when ISTAT renames or moves a column it reads (it checks the header), or uses
a province kind it does not know: update `COLUMNS` or `KINDS` then, and the objects of the
template if a new kind needs a new option (a minor version).

Its output is stable: the same ISTAT file gives the same bytes, records sorted by code, CSV
quoted only where needed. Run on the file of 2026-11-07 it gives exactly the seeds of 1.0.0.
