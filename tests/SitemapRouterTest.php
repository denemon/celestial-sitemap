<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;
use CelestialSitemap\Sitemap\SitemapRouter;

final class SitemapRouterTest extends CelestialSitemap_TestCase
{
    private Options $options;
    private SitemapRouter $router;

    protected function set_up(): void
    {
        parent::set_up();

        $this->options = new Options();
        $this->options->set('cel_sitemap_post_types', []);
        $this->options->set('cel_sitemap_taxonomies', ['category']);

        $this->router = new SitemapRouter($this->options);
    }

    public function test_build_index_lists_all_taxonomy_pages_when_term_count_exceeds_max_urls(): void
    {
        $this->seedTaxonomy('category', 5001);

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        $this->assertStringContainsString('https://example.org/cel-sitemap-category-1.xml', $xml);
        $this->assertStringContainsString('https://example.org/cel-sitemap-category-2.xml', $xml);
        $this->assertStringContainsString('https://example.org/cel-sitemap-category-3.xml', $xml);
        $this->assertStringNotContainsString('https://example.org/cel-sitemap-category.xml', $xml);
    }

    public function test_pregenerate_sitemaps_warms_every_taxonomy_page(): void
    {
        $this->seedTaxonomy('category', 5001);

        $this->router->pregenerateSitemaps();

        $page1 = get_transient('cel_sm_v1_category_p1');
        $page2 = get_transient('cel_sm_v1_category_p2');
        $page3 = get_transient('cel_sm_v1_category_p3');

        $this->assertIsString($page1);
        $this->assertIsString($page2);
        $this->assertIsString($page3);

        $this->assertStringContainsString('https://example.org/term-1/', $page1);
        $this->assertStringContainsString('https://example.org/term-2501/', $page2);
        $this->assertStringContainsString('https://example.org/term-5001/', $page3);
    }

    public function test_build_index_omits_filtered_empty_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 2501);
        $GLOBALS['cel_test_term_meta'][2501]['_cel_noindex'] = '1';

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        $this->assertStringContainsString('https://example.org/cel-sitemap-category.xml', $xml);
        $this->assertStringNotContainsString('https://example.org/cel-sitemap-category-2.xml', $xml);
    }

    public function test_pregenerate_sitemaps_skips_filtered_empty_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 2501);
        $GLOBALS['cel_test_term_meta'][2501]['_cel_noindex'] = '1';

        $this->router->pregenerateSitemaps();

        $this->assertIsString(get_transient('cel_sm_v1_category_p1'));
        $this->assertFalse(get_transient('cel_sm_v1_category_p2'));
    }

    public function test_get_news_urls_falls_back_to_latest_post_when_news_window_is_empty(): void
    {
        $oldDate = gmdate('Y-m-d H:i:s', time() - (72 * HOUR_IN_SECONDS));

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'             => 101,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_date_gmt'  => $oldDate,
            'post_title'     => 'Old News',
            'permalink'      => 'https://example.org/old-news/',
        ];

        $urls = $this->invokePrivateMethod($this->router, 'getNewsUrls');

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.org/old-news/', $urls[0]['loc']);
        $this->assertSame('Old News', $urls[0]['title']);
    }

    public function test_get_news_urls_returns_empty_when_no_published_posts_exist(): void
    {
        $urls = $this->invokePrivateMethod($this->router, 'getNewsUrls');

        $this->assertSame([], $urls);
    }

    public function test_resolve_sitemap_response_returns_404_for_unknown_types(): void
    {
        $response = $this->invokePrivateMethod($this->router, 'resolveSitemapResponse', ['unknown-type', 1]);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('<urlset', $response['xml']);
    }

    public function test_resolve_sitemap_response_returns_404_for_out_of_range_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 1);

        $response = $this->invokePrivateMethod($this->router, 'resolveSitemapResponse', ['category', 2]);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('<urlset', $response['xml']);
    }

    public function test_detect_from_uri_supports_standard_aliases_and_html_hub(): void
    {
        $_SERVER['REQUEST_URI'] = '/sitemap.xml';
        $this->assertSame(['index', 1, false], $this->invokePrivateMethod($this->router, 'detectFromUri'));

        $_SERVER['REQUEST_URI'] = '/sitemap-recent.xml';
        $this->assertSame(['recent', 1, false], $this->invokePrivateMethod($this->router, 'detectFromUri'));

        $_SERVER['REQUEST_URI'] = '/cel-sitemap-hub/';
        $this->assertSame(['hub', 1, false], $this->invokePrivateMethod($this->router, 'detectFromUri'));
    }

    public function test_build_recent_sitemap_only_lists_recent_indexable_urls(): void
    {
        $this->options->set('cel_sitemap_post_types', ['post', 'page']);

        $recent = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $old    = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'                => 101,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => $recent,
            'post_title'        => 'Fresh Post',
            'permalink'         => 'https://example.org/fresh-post/',
        ];
        $GLOBALS['cel_test_posts'][102] = (object) [
            'ID'                => 102,
            'post_type'         => 'page',
            'post_status'       => 'publish',
            'post_modified_gmt' => $recent,
            'post_title'        => 'Fresh Page',
            'permalink'         => 'https://example.org/fresh-page/',
        ];
        $GLOBALS['cel_test_posts'][103] = (object) [
            'ID'                => 103,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => $old,
            'post_title'        => 'Old Post',
            'permalink'         => 'https://example.org/old-post/',
        ];
        $GLOBALS['cel_test_posts'][104] = (object) [
            'ID'                => 104,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => $recent,
            'post_title'        => 'Noindex Post',
            'permalink'         => 'https://example.org/noindex-post/',
        ];
        $GLOBALS['cel_test_post_meta'][104]['_cel_noindex'] = '1';

        $xml = $this->invokePrivateMethod($this->router, 'buildRecentSitemap');

        $this->assertStringContainsString('https://example.org/fresh-post/', $xml);
        $this->assertStringContainsString('https://example.org/fresh-page/', $xml);
        $this->assertStringNotContainsString('https://example.org/old-post/', $xml);
        $this->assertStringNotContainsString('https://example.org/noindex-post/', $xml);
    }

    public function test_build_index_lists_recent_sitemap_when_recent_entries_exist(): void
    {
        $this->options->set('cel_sitemap_post_types', ['post']);

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'                => 101,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'post_title'        => 'Fresh Post',
            'permalink'         => 'https://example.org/fresh-post/',
        ];

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        $this->assertStringContainsString('https://example.org/sitemap-recent.xml', $xml);
    }

    public function test_build_html_hub_lists_recent_content_and_taxonomy_links(): void
    {
        $this->options->set('cel_sitemap_post_types', ['post']);
        $this->seedTaxonomy('category', 1);

        $GLOBALS['cel_test_taxonomies']['category'] = (object) [
            'name'   => 'category',
            'labels' => (object) ['name' => 'Categories'],
        ];

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'                => 101,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'post_title'        => 'Fresh Post',
            'permalink'         => 'https://example.org/fresh-post/',
        ];

        $html = $this->invokePrivateMethod($this->router, 'buildHtmlHub');

        $this->assertStringContainsString('https://example.org/sitemap.xml', $html);
        $this->assertStringContainsString('https://example.org/sitemap-recent.xml', $html);
        $this->assertStringContainsString('https://example.org/fresh-post/', $html);
        $this->assertStringContainsString('https://example.org/term-1/', $html);
        $this->assertStringContainsString('Categories', $html);
    }

    public function test_format_lastmod_emits_site_timezone_offset_for_given_timestamp(): void
    {
        $GLOBALS['cel_test_timezone'] = 'Asia/Tokyo';

        // 2026-04-01 09:00:00 JST = 2026-04-01 00:00:00 UTC
        $timestamp = \gmmktime(0, 0, 0, 4, 1, 2026);

        $lastmod = $this->invokePrivateMethod($this->router, 'formatLastmod', [$timestamp]);

        $this->assertIsString($lastmod);
        $this->assertSame('2026-04-01T09:00:00+09:00', $lastmod);
    }

    public function test_format_lastmod_falls_back_to_current_time_when_timestamp_is_null(): void
    {
        $GLOBALS['cel_test_timezone'] = 'Asia/Tokyo';

        $lastmod = $this->invokePrivateMethod($this->router, 'formatLastmod', [null]);

        // W3C datetime with JST offset.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/',
            (string) $lastmod
        );
    }

    public function test_build_index_uses_site_timezone_for_lastmod_values(): void
    {
        $GLOBALS['cel_test_timezone'] = 'Asia/Tokyo';
        $this->options->set('cel_sitemap_post_types', ['post']);

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'                => 101,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_modified_gmt' => \gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'post_title'        => 'Fresh Post',
            'permalink'         => 'https://example.org/fresh-post/',
        ];

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        // サイトのタイムゾーン（JST）のオフセットが lastmod に含まれること。
        $this->assertMatchesRegularExpression('/<lastmod>[^<]+\+09:00<\/lastmod>/', $xml);
        // 旧実装のような Z サフィックス（UTC）は出力しない。
        $this->assertStringNotContainsString('Z</lastmod>', $xml);
    }

    public function test_render_discovery_links_is_limited_to_hub_pages(): void
    {
        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;

        ob_start();
        $this->router->renderDiscoveryLinks();
        $hubOutput = (string) ob_get_clean();

        $this->assertStringContainsString('https://example.org/cel-sitemap-hub/', $hubOutput);
        $this->assertStringContainsString('https://example.org/sitemap.xml', $hubOutput);

        $GLOBALS['cel_test_conditionals'] = [];

        ob_start();
        $this->router->renderDiscoveryLinks();
        $plainOutput = (string) ob_get_clean();

        $this->assertSame('', $plainOutput);
    }

    private function seedTaxonomy(string $taxonomy, int $count): void
    {
        $GLOBALS['cel_test_term_counts'][$taxonomy] = $count;
        $GLOBALS['cel_test_terms'][$taxonomy] = [];

        for ($i = 1; $i <= $count; $i++) {
            $GLOBALS['cel_test_terms'][$taxonomy][] = (object) [
                'term_id' => $i,
                'name'    => "Term {$i}",
            ];
        }
    }
}
