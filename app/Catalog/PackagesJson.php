<?php

declare(strict_types=1);

namespace RepAhead\Catalog;

use Psr\Log\LoggerInterface;

// Co-located with PackagesJson; PSR-4 cannot autoload this class directly,
// it gets registered as a side effect when PackagesJson is loaded.
final readonly class PackagesJsonResult
{
    public function __construct(
        public string $json,
        public int $packagesCount,
        public int $versionsCount,
        public int $skippedCount,
    ) {
    }
}

final readonly class PackagesJson
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @param iterable<Release> $entries
     * @param callable(Release): ?ZipMeta $reader
     */
    public function build(iterable $entries, callable $reader, string $baseUrl): PackagesJsonResult
    {
        $packages = [];
        $versionCount = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $meta = $reader($entry);
            if ($meta === null) {
                // Cause was already logged by the metadata reader.
                $skipped++;
                continue;
            }
            $cj = $meta->composerJson;
            $expectedName = $entry->fullName();

            if (!isset($cj['name']) || !is_string($cj['name'])) {
                $this->logger->warning('Skipping ZIP missing composer.json name', ['path' => $entry->path]);
                $skipped++;
                continue;
            }
            if ($cj['name'] !== $expectedName) {
                $this->logger->warning('Skipping ZIP with name/path mismatch', [
                    'path' => $entry->path,
                    'composer_name' => $cj['name'],
                    'expected_name' => $expectedName,
                ]);
                $skipped++;
                continue;
            }

            if (isset($cj['version']) && $cj['version'] !== $entry->version) {
                $this->logger->warning('Filename version differs from composer.json version; using filename', [
                    'path' => $entry->path,
                    'filename_version' => $entry->version,
                    'composer_version' => $cj['version'],
                ]);
            }

            $cj['version'] = $entry->version;
            // Composer only updates an installed package whose version is unchanged
            // (e.g. an overwritten dev-main) when its dist reference differs.
            $cj['dist'] = [
                'type' => 'zip',
                'url' => $baseUrl . '/dist/' . $entry->path,
                'reference' => $meta->sha1,
                'shasum' => $meta->sha1,
            ];

            $packages[$expectedName][$entry->version] = $cj;
            $versionCount++;
        }

        ksort($packages);
        foreach ($packages as &$versions) {
            ksort($versions);
        }
        unset($versions);

        // Force {} not [] when no packages.
        $payload = ['packages' => $packages === [] ? new \stdClass() : $packages];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new PackagesJsonResult(
            json: (string) $json,
            packagesCount: count($packages),
            versionsCount: $versionCount,
            skippedCount: $skipped,
        );
    }
}
