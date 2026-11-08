<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Owners;

/**
 * The catalog's maintainers: the users .github/CODEOWNERS names, whose review every pull
 * request needs. Teams (@org/team) are left out: their members cannot be read without the API.
 */
final readonly class Maintainers
{
    public const string CODEOWNERS = '.github/CODEOWNERS';

    /**
     * @param list<string> $logins lowercase
     */
    private function __construct(private array $logins)
    {
    }

    public static function fromCodeowners(string $codeowners): self
    {
        $logins = [];
        foreach (preg_split('~\R~', $codeowners) ?: [] as $line) {
            $line = trim((string) preg_replace('~#.*$~', '', $line));
            if ('' === $line) {
                continue;
            }
            // A pattern, then its owners.
            foreach (\array_slice(preg_split('~\s+~', $line) ?: [], 1) as $owner) {
                if (1 === preg_match('~^@([A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)$~', $owner, $match)) {
                    $logins[] = strtolower($match[1]);
                }
            }
        }

        return new self(array_values(array_unique($logins)));
    }

    public function has(string $login): bool
    {
        return \in_array(strtolower($login), $this->logins, true);
    }
}
