<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces the dependency rule of the hexagon on every `use` statement:
 *   Domain      -> nothing outside App\Domain
 *   Application -> App\Domain, App\Application and PSR interfaces only (no Laravel)
 */
final class ArchitectureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function layers(): iterable
    {
        yield 'Domain' => ['Domain', ['App\\Domain\\']];
        // Random\ is PHP core (ext/random), used for seedable jitter.
        yield 'Application' => ['Application', ['App\\Domain\\', 'App\\Application\\', 'Psr\\', 'Random\\']];
    }

    /**
     * @param  list<string>  $allowedPrefixes
     */
    #[DataProvider('layers')]
    public function test_layer_only_depends_on_allowed_namespaces(string $layer, array $allowedPrefixes): void
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/'.$layer));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $import) {
                $isClassFromGlobalNamespace = ! str_contains($import, '\\');
                $allowed = $isClassFromGlobalNamespace || array_filter($allowedPrefixes, fn (string $prefix): bool => str_starts_with($import, $prefix)) !== [];
                if (! $allowed) {
                    $violations[] = $file->getFilename().' imports '.$import;
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
