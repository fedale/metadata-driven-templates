#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Writes the seeds of fedale/geo-italy (region.csv, province.csv, municipality.csv) from ISTAT's
 * "Elenco dei comuni italiani":
 *
 *   curl -L -o comuni.csv https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.csv
 *   php tools/geo-italy/build-seeds.php comuni.csv templates/fedale/geo-italy/<version>/seeds
 *
 * The ISTAT file is semicolon-separated, Windows-1252, one row per municipality. The columns read
 * (0-based): 0 region code, 1 province code (supra-municipal unit), 4 municipality code
 * (alphanumeric, 6 digits), 5 name (Italian and other language), 10 region name, 11 province
 * name, 12 province kind (1-5), 13 provincial capital (0/1), 14 abbreviation, 19 cadastral code.
 * The header names them; the script checks it before reading anything.
 *
 * The output is sorted by code and written as Python's csv module writes it (a field quoted only
 * when it holds a comma, a quote or a line break, "\n" line ends): the same ISTAT file always gives
 * the same bytes, so a new version's diff shows only what ISTAT changed.
 */

const COLUMNS = [
    0 => 'Codice Regione',
    1 => "Codice dell'Unità territoriale sovracomunale",
    4 => 'Codice Comune formato alfanumerico',
    5 => 'Denominazione (Italiana e straniera)',
    10 => 'Denominazione Regione',
    11 => "Denominazione dell'Unità territoriale sovracomunale",
    12 => 'Tipologia di Unità territoriale sovracomunale',
    13 => 'Flag Comune capoluogo di provincia',
    14 => 'Sigla automobilistica',
    19 => 'Codice Catastale del comune',
];

const KINDS = [
    '1' => 'province',
    '2' => 'autonomous_province',
    '3' => 'metropolitan_city',
    '4' => 'free_consortium',
    '5' => 'non_administrative_unit',
];

function fail(string $message): never
{
    fwrite(\STDERR, $message."\n");
    exit(1);
}

/**
 * @param list<string> $row
 */
function csvLine(array $row): string
{
    return implode(',', array_map(
        static fn (string $field): string => false === strpbrk($field, ",\"\r\n") ? $field : '"'.str_replace('"', '""', $field).'"',
        $row,
    ))."\n";
}

/**
 * @param list<string>       $header
 * @param list<list<string>> $rows
 */
function write(string $directory, string $name, array $header, array $rows): void
{
    usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
    $csv = csvLine($header).implode('', array_map(csvLine(...), $rows));
    if (false === file_put_contents($directory.'/'.$name.'.csv', $csv)) {
        fail(sprintf('%s/%s.csv cannot be written.', $directory, $name));
    }
    printf("%s: %d records\n", $name, count($rows));
}

[, $source, $output] = $argv + [null, null, null];
if (!is_string($source) || !is_string($output)) {
    fail('Usage: php tools/geo-italy/build-seeds.php <Elenco-comuni-italiani.csv> <template>/seeds');
}
if (!is_dir($output)) {
    fail(sprintf('%s is not a directory.', $output));
}
$content = file_get_contents($source);
if (false === $content) {
    fail(sprintf('%s cannot be read.', $source));
}
$content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');

$handle = fopen('php://memory', 'r+');
if (false === $handle) {
    fail('No memory to read the file in.');
}
fwrite($handle, $content);
rewind($handle);
$header = fgetcsv($handle, null, ';', '"', '');
if (!is_array($header)) {
    fail('The file is empty.');
}
foreach (COLUMNS as $index => $start) {
    // ISTAT's headers carry notes and line breaks: compare their start, spaces normalized.
    $name = trim((string) preg_replace('~\s+~u', ' ', (string) ($header[$index] ?? '')));
    if (!str_starts_with($name, $start)) {
        fail(sprintf('Column %d is "%s", not "%s": ISTAT changed the file, update COLUMNS.', $index, $name, $start));
    }
}

$regions = [];
$provinces = [];
$municipalities = [];
while (false !== ($row = fgetcsv($handle, null, ';', '"', ''))) {
    $row = array_map(static fn (?string $field): string => trim((string) $field), $row);
    if ('' === $row[0]) {
        continue;
    }
    if (count($row) < 20) {
        fail(sprintf('A row has %d columns, not 20 or more: %s', count($row), implode(';', $row)));
    }
    $kind = KINDS[$row[12]] ?? fail(sprintf('%s: unknown province kind "%s".', $row[4], $row[12]));
    $regions[$row[0]] = [$row[0], $row[10]];
    $provinces[$row[1]] = [$row[1], $row[11], $row[14], $kind, $row[0]];
    $municipalities[] = [$row[4], $row[5], $row[19], '1' === $row[13] ? 'true' : 'false', $row[1]];
}

write($output, 'region', ['code', 'name'], array_values($regions));
write($output, 'province', ['code', 'name', 'abbreviation', 'kind', 'region'], array_values($provinces));
write($output, 'municipality', ['code', 'name', 'cadastral_code', 'capital', 'province'], $municipalities);
