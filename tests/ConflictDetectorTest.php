<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Admin\ConflictDetector;

final class ConflictDetectorTest extends CelestialSitemap_TestCase
{
    public function test_detects_nothing_in_clean_environment(): void
    {
        $detector = new ConflictDetector();

        // 競合 SEO プラグインが何も有効でない素の環境では空配列が返る。
        $this->assertSame([], $this->invokePrivateMethod($detector, 'detectConflicts'));
    }

    public function test_detects_google_site_kit_when_version_constant_is_defined(): void
    {
        if (! defined('GOOGLESITEKIT_VERSION')) {
            define('GOOGLESITEKIT_VERSION', '1.140.0');
        }

        $detector = new ConflictDetector();
        $detected = $this->invokePrivateMethod($detector, 'detectConflicts');

        // Site Kit は canonical / robots を独自に出力するため、管理画面で警告する必要がある。
        $this->assertContains('Google Site Kit', $detected);
    }
}
