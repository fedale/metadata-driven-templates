<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

/**
 * Symbolic links in the checkout: git stores them, and read through, they could reach any file of
 * the runner. Nothing is read from a version that has one.
 */
final class Links
{
    /**
     * The links of a version directory: the directory itself and each one above it up to the
     * root, and everything inside.
     *
     * @return list<string> one problem per link, located in the repository
     */
    public static function in(string $root, string $directory): array
    {
        $path = $root;
        foreach (explode('/', $directory) as $segment) {
            $path .= '/'.$segment;
            if (is_link($path) || !is_dir($path)) {
                return [\sprintf('%s: Not a directory: symbolic links are not allowed.', substr($path, \strlen($root) + 1))];
            }
        }

        $problems = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isLink()) {
                $problems[] = \sprintf('%s/%s: Symbolic links are not allowed.', $directory, substr($entry->getPathname(), \strlen($path) + 1));
            }
        }

        return $problems;
    }
}
