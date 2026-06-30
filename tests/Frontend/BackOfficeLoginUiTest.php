<?php

declare(strict_types=1);

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

final class BackOfficeLoginUiTest extends TestCase
{
    public function testAuthenticatedSessionCanHideLoginScreenAndRevealDashboard(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $css = file_get_contents($projectRoot . '/public/bo/styles.css');
        $javascript = file_get_contents($projectRoot . '/public/bo/app.js');
        $template = file_get_contents($projectRoot . '/templates/back_office/index.html.twig');

        self::assertIsString($css);
        self::assertMatchesRegularExpression('/\[hidden\]\s*\{\s*display:\s*none\s*!important;/s', $css);

        self::assertIsString($javascript);
        self::assertStringContainsString("$('[data-login-screen]').hidden = authenticated;", $javascript);
        self::assertStringContainsString("$('[data-app-workspace]').setAttribute('aria-hidden', authenticated ? 'false' : 'true');", $javascript);

        self::assertIsString($template);
        self::assertStringContainsString('class="login-screen" data-login-screen', $template);
        self::assertStringContainsString('data-app-workspace', $template);
    }

    public function testPwaAssetsUseTheCurrentCacheVersion(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $serviceWorker = file_get_contents($projectRoot . '/public/service-worker.js');
        $template = file_get_contents($projectRoot . '/templates/back_office/index.html.twig');

        self::assertIsString($serviceWorker);
        self::assertStringContainsString("const CACHE_NAME = 'aldahim-bo-v10';", $serviceWorker);
        self::assertStringContainsString("'/bo/styles.css?v=10'", $serviceWorker);
        self::assertStringNotContainsString('v=9', $serviceWorker);

        self::assertIsString($template);
        self::assertStringContainsString('/bo/styles.css?v=10', $template);
        self::assertStringContainsString('/bo/app.js?v=10', $template);
        self::assertStringNotContainsString('v=9', $template);
    }

    public function testDashboardExposesUserManagementWithoutBoDeletion(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $javascript = file_get_contents($projectRoot . '/public/bo/app.js');
        $template = file_get_contents($projectRoot . '/templates/back_office/index.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString('data-view="users"', $template);
        self::assertStringContainsString('<option value="BO">BO</option>', $template);
        self::assertStringContainsString('<option value="PRESTATAIRE">Prestataires</option>', $template);
        self::assertStringContainsString('<option value="CLIENT">Clients</option>', $template);

        self::assertIsString($javascript);
        self::assertStringContainsString("user.type === 'BO' ? ''", $javascript);
        self::assertStringContainsString('/api/v1/admin/users/', $javascript);
    }
}
