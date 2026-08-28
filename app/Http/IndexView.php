<?php

declare(strict_types=1);

namespace RepAhead\Http;

/**
 * Renders the human-facing landing page from the decoded packages.json Index:
 * one card per Package, listing its Releases newest-first with an install
 * command to copy for each Release. Satis-style, with no external assets.
 */
final class IndexView
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $packages
     *        Index `packages` map: name => (version => composer.json payload).
     */
    public static function render(array $packages, string $baseUrl): string
    {
        $cards = '';
        foreach ($packages as $name => $versions) {
            $cards .= self::package((string) $name, $versions);
        }
        if ($cards === '') {
            $cards = '<p class="empty">No packages published yet.</p>';
        }

        return self::document(count($packages), $baseUrl, $cards);
    }

    /** @param array<string, array<string, mixed>> $versions */
    private static function package(string $name, array $versions): string
    {
        // packages.json sorts versions lexically; present them semver newest-first.
        $ordered = $versions;
        uksort($ordered, static fn ($a, $b): int => version_compare($b, $a));
        $firstKey = array_key_first($ordered);
        $latest = $firstKey === null ? [] : $ordered[$firstKey];

        $description = isset($latest['description']) && is_string($latest['description'])
            ? '<p class="desc">' . self::e($latest['description']) . '</p>'
            : '';

        $devVersions = [];
        $stableVersions = [];
        foreach ($ordered as $version => $info) {
            if (str_starts_with($version, 'dev-')) {
                $devVersions[$version] = $info;
            } else {
                $stableVersions[$version] = $info;
            }
        }

        $groups = '';
        if ($stableVersions !== []) {
            $groups .= self::versionGroup($name, 'Releases', $stableVersions, 'versions', 'version-group');
        }
        if ($devVersions !== []) {
            $groups .= self::versionGroup(
                $name,
                'Dev builds',
                $devVersions,
                'versions versions-dev',
                'version-group version-group-dev',
            );
        }

        $safeName = self::e($name);
        $command = 'composer require ' . $name;
        $require = self::e($command);
        $size = strlen($command) + 1;
        return <<<HTML
        <article class="pkg">
            <div class="pkg-head">
                <h2>{$safeName}</h2>
                <input class="copy" type="text" readonly size="{$size}" value="{$require}" data-composer-command="{$require}" aria-label="Install command, click to copy">
            </div>
            {$description}
            {$groups}
        </article>

        HTML;
    }

    /** @param array<string, array<string, mixed>> $versions */
    private static function versionGroup(
        string $name,
        string $label,
        array $versions,
        string $listClass,
        string $groupClass,
    ): string {
        $rows = '';
        foreach (array_keys($versions) as $version) {
            $rows .= self::version($name, (string) $version);
        }
        $safeLabel = self::e($label);
        $safeListClass = self::e($listClass);
        $safeGroupClass = self::e($groupClass);
        return <<<HTML
        <div class="{$safeGroupClass}">
            <h3 class="group-label">{$safeLabel}</h3>
            <ul class="{$safeListClass}">{$rows}</ul>
        </div>
        HTML;
    }

    private static function version(string $name, string $version): string
    {
        $label = self::e($version);
        $command = self::e("composer require {$name}:{$version}");
        $ariaLabel = self::e("Copy install command for {$name} {$version}");

        return "<li><button class=\"version-copy\" type=\"button\" data-composer-command=\"{$command}\" aria-label=\"{$ariaLabel}\"><span class=\"ver\">{$label}</span><span class=\"copy-label\">copy</span></button></li>";
    }

    private static function document(int $count, string $baseUrl, string $cards): string
    {
        $host = self::e($baseUrl);
        $plural = $count === 1 ? 'package' : 'packages';
        $snippet = self::e(self::repositorySnippet($baseUrl));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$host}</title>
            <style>
                :root { color-scheme: light dark; }
                * { box-sizing: border-box; }
                body {
                    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    margin: 0; padding: 2rem 1rem; max-width: 60rem; margin-inline: auto;
                    color: #1a1a1a; background: #fafafa;
                }
                header { margin-bottom: 2rem; }
                h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
                .meta { color: #888; margin: 0; }
                pre {
                    background: #f0f0f0; padding: .75rem 1rem; border-radius: 6px;
                    overflow-x: auto; font-size: 13px; margin: 1rem 0 0;
                }
                .pkg {
                    background: #fff; border: 1px solid #e5e5e5; border-radius: 8px;
                    padding: 1rem 1.25rem; margin-bottom: 1rem; overflow: hidden;
                }
                .pkg-head {
                    display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem;
                    justify-content: space-between;
                }
                .pkg h2 { font-size: 1.1rem; margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
                .copy {
                    font: 13px/1 ui-monospace, SFMono-Regular, Menlo, monospace;
                    background: #f6f6f6; border: 1px solid #ddd; border-radius: 6px;
                    padding: .4rem .6rem; color: #1a1a1a; cursor: pointer;
                    flex: 0 1 auto; max-width: 100%;
                }
                .copy:hover { border-color: #2563eb; }
                .copy.copied { border-color: #16a34a; color: #16a34a; }
                .desc { color: #666; margin: .35rem 0 .75rem; }
                .version-group { margin-top: .75rem; }
                .version-group:first-child { margin-top: .5rem; }
                .group-label {
                    font-size: 11px; font-weight: 700; text-transform: uppercase;
                    letter-spacing: .06em; color: #999; margin: 0 0 .4rem;
                }
                .version-group.version-group-dev {
                    margin: 1rem -1.25rem -1rem; padding: .6rem 1.25rem;
                    background: #f7f7f7; border-top: 1px solid #ececec;
                    border-radius: 0 0 8px 8px;
                }
                .versions { list-style: none; margin: 0; padding: 0;
                    display: flex; flex-wrap: wrap; gap: .4rem; }
                .versions li {
                    display: inline-flex;
                }
                .version-copy {
                    display: inline-flex; align-items: center; gap: .5rem;
                    background: #f0f0f0; border: 1px solid transparent; border-radius: 6px;
                    padding: .3rem .65rem; color: inherit; cursor: pointer;
                }
                .version-copy:hover { border-color: #2563eb; }
                .version-copy.copied { border-color: #16a34a; color: #16a34a; }
                .copy-label {
                    color: #2563eb; font-size: 12px;
                }
                .versions-dev .version-copy { background: #fff; border-color: #ddd; }
                @media (prefers-color-scheme: dark) {
                    body { color: #e6e6e6; background: #161616; }
                    .pkg { background: #1f1f1f; border-color: #333; }
                    pre, .copy { background: #111; }
                    .copy { border-color: #333; color: #e6e6e6; }
                    .version-copy { background: #292929; }
                    .version-group.version-group-dev { background: #191919; border-top-color: #2c2c2c; }
                    .versions-dev .version-copy { background: #262626; border-color: #3a3a3a; }
                    .group-label { color: #888; }
                }
                .ver { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; font-weight: 600; }
                .empty { color: #888; }
            </style>
        </head>
        <body>
            <header>
                <h1>{$host}</h1>
                <p class="meta">{$count} {$plural}</p>
                <pre>{$snippet}</pre>
            </header>
            {$cards}
            <script>
                function copyToClipboard(value) {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(value);
                        return;
                    }

                    var textarea = document.createElement('textarea');
                    textarea.value = value;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    textarea.remove();
                }

                document.addEventListener('click', function (e) {
                    var target = e.target.closest('[data-composer-command]');
                    if (!target) return;
                    copyToClipboard(target.dataset.composerCommand);
                    target.classList.add('copied');
                    setTimeout(function () { target.classList.remove('copied'); }, 1000);
                });
            </script>
        </body>
        </html>

        HTML;
    }

    private static function repositorySnippet(string $baseUrl): string
    {
        return (string) json_encode(
            ['repositories' => [['type' => 'composer', 'url' => $baseUrl]]],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
