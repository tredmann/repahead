<?php

declare(strict_types=1);

namespace RepAhead\Tests\Http;

use PHPUnit\Framework\TestCase;
use RepAhead\Http\IndexView;

final class IndexViewTest extends TestCase
{
    public function testRendersPackagesGroupedWithVersionInstallCommands(): void
    {
        $html = IndexView::render([
            'acme/billing' => [
                '1.0.0' => [
                    'dist' => ['type' => 'zip', 'url' => 'https://example.com/dist/acme/billing/1.0.0.zip'],
                ],
                '1.1.0' => [
                    'description' => 'Billing toolkit',
                    'dist' => ['type' => 'zip', 'url' => 'https://example.com/dist/acme/billing/1.1.0.zip'],
                ],
            ],
        ], 'https://example.com');

        self::assertStringContainsString('acme/billing', $html);
        self::assertStringContainsString('Billing toolkit', $html);
        self::assertStringContainsString('data-composer-command="composer require acme/billing:1.0.0"', $html);
        self::assertStringContainsString('data-composer-command="composer require acme/billing:1.1.0"', $html);
        self::assertStringNotContainsString('https://example.com/dist/acme/billing/1.0.0.zip', $html);
        self::assertStringNotContainsString('https://example.com/dist/acme/billing/1.1.0.zip', $html);
        // Newest version first.
        self::assertLessThan(strpos($html, '1.0.0'), strpos($html, '1.1.0'));
    }

    public function testEscapesUntrustedFields(): void
    {
        $html = IndexView::render([
            'evil/pkg' => [
                '1.0.0' => [
                    'description' => '<script>alert(1)</script>',
                    'dist' => ['type' => 'zip', 'url' => 'https://example.com/dist/evil/pkg/1.0.0.zip'],
                ],
            ],
        ], 'https://example.com');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRendersEmptyStateWhenNoPackages(): void
    {
        $html = IndexView::render([], 'https://example.com');

        self::assertStringContainsString('No packages published yet.', $html);
        self::assertStringContainsString('0 packages', $html);
    }

    public function testShowsComposerRepositorySnippet(): void
    {
        $html = IndexView::render([], 'https://example.com');

        // The snippet is HTML-escaped so quotes render literally in the browser.
        self::assertStringContainsString('&quot;type&quot;: &quot;composer&quot;', $html);
        self::assertStringContainsString('https://example.com', $html);
    }
}
