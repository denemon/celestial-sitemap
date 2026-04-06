<?php

declare(strict_types=1);

// WordPress の公式テストライブラリの配置先は環境変数で上書きできるようにする。
$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

if (file_exists($_tests_dir . '/includes/functions.php')) {
    require_once $_tests_dir . '/includes/functions.php';

    // PHPUnit 起動時にプラグイン本体を読み込む。
    tests_add_filter('muplugins_loaded', static function (): void {
        require dirname(__DIR__) . '/celestial-sitemap.php';
    });

    require $_tests_dir . '/includes/bootstrap.php';
    return;
}

require_once __DIR__ . '/wp-stubs.php';
require dirname(__DIR__) . '/celestial-sitemap.php';
