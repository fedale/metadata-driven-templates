<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

/**
 * The catalog as the branch a pull request goes into has it, read from git: the pull request
 * cannot change what it is checked against.
 */
final readonly class BaseBranch
{
    /**
     * @param list<string> $published the version directories ("templates/acme/shop/1.0.0"): read
     *                                from git, not from the checkout, where a published directory
     *                                and a new one look alike
     */
    public function __construct(
        public Vendors $vendors,
        public array $published,
        public Yanked $yanked,
    ) {
    }

    /**
     * @throws \RuntimeException git fails, or a file of the base is invalid
     */
    public static function read(Git $git, string $base): self
    {
        try {
            return new self(
                Vendors::fromYaml($git->show($base, 'vendors.yaml') ?? ''),
                $git->versionDirectories($base),
                Yanked::fromYaml($git->show($base, 'yanked.yaml') ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(\sprintf('The base branch is invalid: %s', $e->getMessage()), 0, $e);
        }
    }
}
