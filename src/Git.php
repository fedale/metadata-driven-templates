<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The checkout of a pull request, read through git.
 */
final readonly class Git
{
    public function __construct(private string $repository)
    {
    }

    /**
     * What the pull request changes since it left the base branch (base...HEAD), renames
     * reported as a deletion plus an addition.
     *
     * @return list<ChangedFile>
     *
     * @throws \RuntimeException git fails, the base is unknown for instance
     */
    public function changesSince(string $base): array
    {
        return self::parseNameStatus($this->run(['diff', '--name-status', '-z', '--no-renames', $base.'...HEAD']));
    }

    /**
     * A file as it is on the base branch; null when the base has no such file.
     *
     * @throws \RuntimeException git fails for another reason
     */
    public function show(string $base, string $path): ?string
    {
        if ('' === trim($this->run(['ls-tree', '--name-only', $base, '--', $path]))) {
            return null;
        }

        return $this->run(['show', $base.':'.$path]);
    }

    /**
     * The version directories on the base branch: templates/<vendor>/<name>/<version>.
     *
     * @return list<string>
     *
     * @throws \RuntimeException git fails
     */
    public function versionDirectories(string $base): array
    {
        $directories = explode("\0", rtrim($this->run(['ls-tree', '-d', '-r', '-z', '--name-only', $base, '--', PullRequestCheck::TEMPLATES]), "\0"));

        return array_values(array_filter($directories, static fn (string $directory): bool => 4 === \count(explode('/', $directory))));
    }

    /**
     * Parses `git diff --name-status -z`: a status, then a path, each ended by a NUL byte.
     *
     * @return list<ChangedFile>
     */
    public static function parseNameStatus(string $output): array
    {
        $fields = explode("\0", rtrim($output, "\0"));
        $changes = [];
        for ($i = 0; $i + 1 < \count($fields); $i += 2) {
            $changes[] = new ChangedFile($fields[$i][0] ?? '', $fields[$i + 1]);
        }

        return $changes;
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->repository);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException(\sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())), 0, $e);
        }

        return $process->getOutput();
    }
}
