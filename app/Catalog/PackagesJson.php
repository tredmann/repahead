<?php

declare(strict_types=1);

namespace RepAhead\Catalog;

use Psr\Log\LoggerInterface;

// Co-located with PackagesJson; PSR-4 cannot autoload this class directly,
// it gets registered as a side effect when PackagesJson is loaded.
final readonly class PackagesJsonResult
{
    public function __construct(
        public string $p2Json,
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
            $cj['dist'] = [
                'type' => 'zip',
                'url' => $baseUrl . '/dist/' . $entry->path,
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

        // p2 manifest: one entry per Package, version objects newest-first.
        $p2 = [];
        foreach ($packages as $name => $versions) {
            // Newest version first — version_compare is semver-aware (native, no dependency).
            uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
            $p2[$name] = array_values($versions);
        }

        // Root Index: inline v1 `packages` plus the v2 metadata-url discovery keys.
        $root = [
            'packages' => $packages === [] ? new \stdClass() : $packages,
            'metadata-url' => $baseUrl . '/p2/%package%.json',
            'available-packages' => array_keys($packages),
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        $json = json_encode($root, $flags);
        $p2Json = json_encode($p2 === [] ? new \stdClass() : $p2, $flags);

        return new PackagesJsonResult(
            p2Json: (string) $p2Json,
            json: (string) $json,
            packagesCount: count($packages),
            versionsCount: $versionCount,
            skippedCount: $skipped,
        );
    }
}
