<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Fedale\TemplateFormat\Constraint;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\Problem;

/**
 * The templates a version requires (spec.requires), as the catalog publishes them: for each, the
 * highest version its constraint allows and that is not yanked, the one an installation would
 * take. The format's checker checks the version against them: its references to their items,
 * its ids against theirs.
 *
 * A requirement the catalog does not publish in a fitting version is refused: an installation
 * taking the template from the catalog would find it nowhere.
 */
final readonly class Requirements
{
    /**
     * @param array<string, list<string>>        $versions            name => the versions the catalog publishes
     * @param \Closure(string, string): ?Package $load                reads a published version, null when it cannot be read
     * @param bool                               $yankedIfNothingElse take a yanked version when no other fits: a build
     *                                                                rechecks what was published, and a yank must not stop it
     */
    public function __construct(
        private array $versions,
        private Yanked $yanked,
        private \Closure $load,
        private bool $yankedIfNothingElse = false,
    ) {
    }

    /**
     * The required versions, and the requirements the catalog does not publish; check() turns
     * them into the checker's problems.
     *
     * @return array{list<Package>, array<string, string>} the versions, and name => constraint of those missing
     */
    public function of(Package $package): array
    {
        $required = [];
        $missing = [];
        foreach (self::requires($package) as $name => $constraint) {
            $found = $this->pick($name, $constraint);
            if (false === $found) {
                // Not a constraint: the format's checker reports it, with its place.
                continue;
            }
            if (null === $found) {
                $missing[$name] = $constraint;
                continue;
            }
            $required[] = $found;
        }

        return [$required, $missing];
    }

    /**
     * The format's checks of a version against the templates it requires, as the catalog
     * publishes them; a requirement it does not publish is said so, rather than as the checker
     * says it ("it must be installed"), which reads as advice to an installation.
     *
     * @return list<Problem>
     */
    public function check(PackageChecker $checker, Package $package): array
    {
        [$required, $missing] = $this->of($package);
        // The missing requirements first: the references to their items fail with them.
        $problems = [];
        foreach ($missing as $name => $constraint) {
            $problems[] = new Problem(Format::MANIFEST, 'spec.requires.'.$name, \sprintf('The catalog publishes no version of %s that fits %s and is not yanked: publish it first, in a pull request of its own.', $name, $constraint));
        }

        return [...$problems, ...array_values(array_filter(
            $checker->check($package, $required),
            static fn (Problem $problem): bool => !(Format::MANIFEST === $problem->path && \in_array(substr($problem->at, \strlen('spec.requires.')), array_map(strval(...), array_keys($missing)), true)),
        ))];
    }

    /**
     * The first cycle the version would close, following each requirement's own requirements
     * as the catalog publishes them: "a requires b requires a". Null when there is none.
     */
    public function cycle(Package $package): ?string
    {
        return $this->walk($package, [$package->name()], []);
    }

    /**
     * @param non-empty-list<string> $path
     * @param array<string, true>    $seen the templates whose requirements were followed already
     */
    private function walk(Package $package, array $path, array $seen): ?string
    {
        foreach (self::requires($package) as $name => $constraint) {
            if (\in_array($name, $path, true)) {
                return implode(' requires ', [...$path, $name]);
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $found = $this->pick($name, $constraint);
            if ($found instanceof Package) {
                $cycle = $this->walk($found, [...$path, $name], $seen);
                if (null !== $cycle) {
                    return $cycle;
                }
            }
        }

        return null;
    }

    /**
     * @return Package|false|null the version taken; null when none fits; false when the
     *                            constraint is not one
     */
    private function pick(string $name, string $constraint): Package|false|null
    {
        try {
            $parsed = Constraint::parse($constraint);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $fitting = array_values(array_filter($this->versions[$name] ?? [], static fn (string $version): bool => $parsed->allows($version)));
        usort($fitting, static fn (string $a, string $b): int => version_compare($b, $a));
        // Every version of a removed template counts as yanked.
        $usable = array_values(array_filter($fitting, fn (string $version): bool => !$this->yanked->has($name, $version)));
        $version = $usable[0] ?? ($this->yankedIfNothingElse ? ($fitting[0] ?? null) : null);

        return null === $version ? null : ($this->load)($name, $version);
    }

    /**
     * @return array<string, string> the templates a version requires: name => constraint
     */
    private static function requires(Package $package): array
    {
        $requires = [];
        foreach (\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : [] as $name => $constraint) {
            if ('format' !== (string) $name && \is_string($constraint)) {
                $requires[(string) $name] = $constraint;
            }
        }

        return $requires;
    }
}
