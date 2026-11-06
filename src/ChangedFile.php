<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

/**
 * A file a pull request adds, modifies or deletes, relative to the root of the repository.
 */
final readonly class ChangedFile
{
    public function __construct(
        /** Git's status letter: A (added), M (modified), D (deleted), T (type changed). */
        public string $status,
        public string $path,
    ) {
    }

    public function isAdded(): bool
    {
        return 'A' === $this->status;
    }
}
