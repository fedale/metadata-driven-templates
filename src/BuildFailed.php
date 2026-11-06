<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

/**
 * The catalog cannot be published: nothing was written.
 */
final class BuildFailed extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems, ?\Throwable $previous = null)
    {
        parent::__construct(implode("\n", $problems), 0, $previous);
    }
}
