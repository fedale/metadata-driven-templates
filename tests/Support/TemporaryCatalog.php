<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Support;

/**
 * A catalog checkout in a temporary directory, with versions of the example templates copied
 * into it: acme/shop, and acme/places and acme/people (requiring acme/places ^1.0).
 */
trait TemporaryCatalog
{
    private const string EXAMPLE = __DIR__.'/../Fixtures/shop';
    private const string FIXTURES = __DIR__.'/../Fixtures';

    private string $root;

    private function createCatalog(): void
    {
        $this->root = sys_get_temp_dir().'/template-catalog-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/templates', 0o777, true);
    }

    /**
     * Copies the example template as templates/<name>/<version>/, its manifest saying
     * $manifestVersion (the directory's version by default).
     */
    private function addVersion(string $version, string $name = 'acme/shop', ?string $manifestVersion = null): string
    {
        $directory = 'templates/'.$name.'/'.$version;
        self::copy(self::EXAMPLE, $this->root.'/'.$directory);
        $manifest = $this->root.'/'.$directory.'/template.yaml';
        $yaml = (string) file_get_contents($manifest);
        file_put_contents($manifest, str_replace(['id: acme/shop', 'version: 1.0.0'], ['id: '.$name, 'version: '.($manifestVersion ?? $version)], $yaml));

        return $directory;
    }

    /**
     * Copies acme/places or acme/people as templates/acme/<fixture>/<version>/, requiring other
     * templates when given (replacing those of the fixture).
     *
     * @param array<string, string>|null $requires name => constraint
     */
    private function addFixture(string $fixture, string $version, ?array $requires = null): string
    {
        $directory = 'templates/acme/'.$fixture.'/'.$version;
        self::copy(self::FIXTURES.'/'.$fixture, $this->root.'/'.$directory);
        $manifest = $this->root.'/'.$directory.'/template.yaml';
        $yaml = (string) preg_replace('/^  version: .*$/m', '  version: '.$version, (string) file_get_contents($manifest));
        if (null !== $requires) {
            $lines = array_map(static fn (string $name, string $constraint): string => \sprintf('    %s: %s', $name, $constraint), array_keys($requires), $requires);
            $yaml = (string) preg_replace('/^  requires:\n(    .*\n)+/m', "  requires:\n    format: metadata-driven/v1\n".implode('', array_map(static fn (string $line): string => $line."\n", $lines)), $yaml);
        }
        file_put_contents($manifest, $yaml);

        return $directory;
    }

    private function removeCatalog(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $target = $to.'/'.substr($entry->getPathname(), \strlen($from) + 1);
            $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
        }
    }

    /**
     * Every file under a directory of the catalog, as a pull request adding them would list them.
     *
     * @return list<string>
     */
    private function filesOf(string $directory): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $files[] = substr($entry->getPathname(), \strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }
}
