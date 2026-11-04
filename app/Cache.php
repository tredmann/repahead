<?php

declare(strict_types=1);

namespace RepAhead;

use RuntimeException;

final readonly class Cache
{
    public const PACKAGES = 'packages.json';
    public const P2 = 'p2.json';

    private string $dir;
    private string $hashFile;
    private string $lockFile;

    public function __construct(string $dir, private int $ttlSeconds)
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Cache directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Cache directory is not writable: $dir");
        }
        $this->dir = $dir;
        $this->hashFile = "$dir/manifest.hash";
        $this->lockFile = "$dir/.rebuild.lock";
    }

    public function readIfFresh(string $name): ?string
    {
        if ($this->ttlSeconds <= 0) {
            return null;
        }
        if (!is_file("$this->dir/$name") || !is_file($this->hashFile)) {
            return null;
        }
        clearstatcache();
        $age = time() - (int) filemtime($this->hashFile);
        if ($age >= $this->ttlSeconds) {
            return null;
        }
        return file_get_contents("$this->dir/$name") ?: null;
    }

    public function readIfHashMatches(string $name, string $hash): ?string
    {
        if (!is_file("$this->dir/$name") || !is_file($this->hashFile)) {
            return null;
        }
        $stored = trim((string) file_get_contents($this->hashFile));
        if ($stored !== $hash) {
            return null;
        }
        @touch($this->hashFile);
        return file_get_contents("$this->dir/$name") ?: null;
    }

    /**
     * @param callable(): array<string,string> $build  artifact-name => content
     * @return array<string,string>
     */
    public function rebuild(string $newHash, callable $build): array
    {
        $lock = fopen($this->lockFile, 'cb');
        if ($lock === false) {
            throw new RuntimeException("Failed to open lock: {$this->lockFile}");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire rebuild lock');
            }

            // Re-check inside the lock — another process may have rebuilt.
            if (is_file($this->hashFile)) {
                $stored = trim((string) file_get_contents($this->hashFile));
                if ($stored === $newHash) {
                    $existing = $this->readAll();
                    if ($existing !== null) {
                        @touch($this->hashFile);
                        return $existing;
                    }
                }
            }

            $artifacts = $build();
            foreach ($artifacts as $name => $content) {
                $this->atomicWrite("$this->dir/$name", $content);
            }
            $this->atomicWrite($this->hashFile, $newHash);
            return $artifacts;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function invalidate(): void
    {
        @unlink($this->hashFile);
    }

    /** @return array<string,string>|null null if any known artifact is missing */
    private function readAll(): ?array
    {
        $out = [];
        foreach ([self::PACKAGES, self::P2] as $name) {
            if (!is_file("$this->dir/$name")) {
                return null;
            }
            $out[$name] = (string) file_get_contents("$this->dir/$name");
        }
        return $out;
    }

    private function atomicWrite(string $target, string $content): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Failed to write temp file: $tmp");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to rename $tmp -> $target");
        }
    }
}
