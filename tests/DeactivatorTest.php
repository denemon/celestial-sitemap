<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Deactivator;

final class DeactivatorTest extends CelestialSitemap_TestCase
{
    public function test_remove_directory_deletes_top_level_files_and_leaves_nested_directories(): void
    {
        $dir = WP_CONTENT_DIR . '/cache/cel-sitemaps/' . get_current_blog_id() . '/remove-directory-test';
        $nested = $dir . '/nested';

        wp_mkdir_p($nested);
        file_put_contents($dir . '/top-level.xml', '<xml />');
        file_put_contents($nested . '/keep-me.xml', '<xml />');

        Deactivator::removeDirectory($dir);

        // 実装どおり、直下ファイルだけ削除してサブディレクトリは触らない。
        $this->assertFalse(file_exists($dir . '/top-level.xml'));
        $this->assertTrue(is_dir($nested));
        $this->assertTrue(file_exists($nested . '/keep-me.xml'));
    }

    public function test_deactivate_clears_plugin_transients_unschedules_cron_and_removes_cache_dir(): void
    {
        global $wpdb;

        $cacheDir = WP_CONTENT_DIR . '/cache/cel-sitemaps/' . get_current_blog_id();

        wp_mkdir_p($cacheDir);
        file_put_contents($cacheDir . '/cached-sitemap.xml', '<xml />');
        set_transient('cel_unit_cache', 'cached', HOUR_IN_SECONDS);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'cel_cleanup_404_log');
        wp_schedule_single_event(time() + (2 * MINUTE_IN_SECONDS), 'cel_pregenerate_sitemaps');

        Deactivator::deactivate();

        // deactivate() は一時データ・cron・キャッシュディレクトリをまとめて掃除する。
        $transient = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                '_transient_cel_unit_cache'
            )
        );

        $this->assertNull($transient);
        $this->assertFalse(wp_next_scheduled('cel_cleanup_404_log'));
        $this->assertFalse(wp_next_scheduled('cel_pregenerate_sitemaps'));
        $this->assertFalse(is_dir($cacheDir));
    }
}
