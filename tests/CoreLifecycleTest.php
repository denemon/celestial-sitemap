<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Activator;
use CelestialSitemap\Core\Migrator;

final class CoreLifecycleTest extends CelestialSitemap_TestCase
{
    public function test_activate_creates_tables_sets_defaults_and_schedules_cleanup(): void
    {
        delete_option('cel_version');
        delete_option('cel_sitemap_post_types');

        // activate() 実行後は DB/option/cron の初期状態が揃うべき。
        Activator::activate();

        $this->assertTrue($this->tableExists('cel_redirects'));
        $this->assertTrue($this->tableExists('cel_404_log'));
        $this->assertSame(CEL_VERSION, get_option('cel_version'));
        $this->assertSame(['post', 'page'], get_option('cel_sitemap_post_types'));
        $this->assertNotFalse(wp_next_scheduled('cel_cleanup_404_log'));
    }

    public function test_ensure_schema_does_not_schedule_cron_side_effects(): void
    {
        // Migrator から呼ばれる ensureSchema() は副作用を増やさない想定。
        Activator::ensureSchema();

        $this->assertTrue($this->tableExists('cel_redirects'));
        $this->assertTrue($this->tableExists('cel_404_log'));
        $this->assertFalse(wp_next_scheduled('cel_cleanup_404_log'));
    }

    public function test_maybe_run_updates_version_and_sets_rewrite_flush_flag_when_upgrading(): void
    {
        update_option('cel_version', '3.0.0');
        delete_option('cel_flush_rewrite_rules');

        // 古いバージョンからの更新時はバージョンと flush フラグが更新される。
        Migrator::maybeRun();

        $this->assertSame(CEL_VERSION, get_option('cel_version'));
        $this->assertSame(1, (int) get_option('cel_flush_rewrite_rules'));
    }

    public function test_maybe_run_is_noop_when_version_is_already_current(): void
    {
        update_option('cel_version', CEL_VERSION);
        delete_option('cel_flush_rewrite_rules');

        // 現行バージョンと一致していれば余計なフラグは立てない。
        Migrator::maybeRun();

        $this->assertSame(CEL_VERSION, get_option('cel_version'));
        $this->assertFalse(get_option('cel_flush_rewrite_rules', false));
    }
}
