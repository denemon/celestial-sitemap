<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;
use CelestialSitemap\Schema\SchemaManager;
use CelestialSitemap\SEO\CanonicalManager;
use CelestialSitemap\SEO\HeadManager;
use CelestialSitemap\SEO\RobotsManager;

final class SeoManagersTest extends CelestialSitemap_TestCase
{
    public function test_resolve_uses_the_posts_page_permalink_for_static_blog_indexes(): void
    {
        update_option('page_for_posts', 42);
        $GLOBALS['cel_test_conditionals']['is_home'] = true;
        $GLOBALS['cel_test_posts'][42] = (object) [
            'ID'        => 42,
            'post_type' => 'page',
            'permalink' => 'https://example.org/news/',
        ];

        $manager = new CanonicalManager(new Options());

        $this->assertSame('https://example.org/news/', $manager->resolve());
    }

    public function test_build_ogp_uses_website_type_for_non_post_singular_pages(): void
    {
        $post = (object) [
            'ID'         => 77,
            'post_type'  => 'page',
            'post_status'=> 'publish',
            'post_title' => 'Landing Page',
            'permalink'  => 'https://example.org/landing/',
        ];

        $GLOBALS['cel_test_conditionals']['is_singular'] = true;
        $GLOBALS['cel_test_posts'][77] = $post;
        $GLOBALS['cel_test_current_post'] = $post;
        $GLOBALS['cel_test_current_post_id'] = 77;
        $GLOBALS['cel_test_document_title'] = 'Landing Page';
        $GLOBALS['cel_test_post_meta'][77]['_cel_canonical'] = 'https://example.org/landing/';
        $GLOBALS['cel_test_post_meta'][77]['_cel_description'] = 'Landing description';

        $options   = new Options();
        $head      = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));
        $ogp       = $this->invokePrivateMethod($head, 'buildOgp');

        $this->assertSame('website', $ogp['og:type']);
        $this->assertSame('https://example.org/landing/', $ogp['og:url']);
        $this->assertArrayNotHasKey('article:published_time', $ogp);
        $this->assertArrayNotHasKey('article:modified_time', $ogp);
    }

    public function test_robots_txt_points_to_the_standard_sitemap_alias(): void
    {
        $manager = new RobotsManager(new Options());

        $output = $manager->filterRobotsTxt("User-agent: *\nDisallow:\n", '1');

        $this->assertStringContainsString('Sitemap: https://example.org/sitemap.xml', $output);
        $this->assertStringNotContainsString('Sitemap: https://example.org/cel-sitemap.xml', $output);
    }

    public function test_build_verification_tags_emits_configured_providers_on_front_page(): void
    {
        $options = new Options();
        $options->set('cel_verify_google', 'google-code-123');
        $options->set('cel_verify_bing', 'bing-code-456');
        $options->set('cel_verify_pinterest', 'pin-code-789');
        // 空値のプロバイダは出力されないこと。
        $options->set('cel_verify_yandex', '');

        $head = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;

        $html = (string) $this->invokePrivateMethod($head, 'buildVerificationTags');

        $this->assertStringContainsString('<meta name="google-site-verification" content="google-code-123"', $html);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="bing-code-456"', $html);
        $this->assertStringContainsString('<meta name="p:domain_verify" content="pin-code-789"', $html);
        $this->assertStringNotContainsString('yandex-verification', $html);
        $this->assertStringNotContainsString('baidu-site-verification', $html);
    }

    public function test_build_verification_tags_skips_non_front_page(): void
    {
        $options = new Options();
        $options->set('cel_verify_google', 'google-code-123');

        $head = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        // デフォルトで is_front_page/is_home は false。
        $html = (string) $this->invokePrivateMethod($head, 'buildVerificationTags');

        $this->assertSame('', $html);
    }

    public function test_build_feed_link_emits_rss_alternate_on_front_page_when_feed_links_suppressed(): void
    {
        $options = new Options();
        $head    = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;
        $GLOBALS['cel_test_bloginfo']['name']        = 'Celestial News';
        $GLOBALS['cel_test_bloginfo']['rss2_url']    = 'https://example.org/feed/';
        // feed_links が外されている場合にのみ自前で補填する。
        $GLOBALS['cel_wp_actions']['wp_head'] = [];

        $html = (string) $this->invokePrivateMethod($head, 'buildFeedLink');

        $this->assertStringContainsString('<link rel="alternate"', $html);
        $this->assertStringContainsString('type="application/rss+xml"', $html);
        $this->assertStringContainsString('href="https://example.org/feed/"', $html);
        $this->assertStringContainsString('Celestial News', $html);
    }

    public function test_build_feed_link_is_noop_when_core_feed_links_are_active(): void
    {
        $options = new Options();
        $head    = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;
        $GLOBALS['cel_test_bloginfo']['rss2_url']          = 'https://example.org/feed/';
        // WordPress コアの feed_links が登録されている状態を再現する。
        $GLOBALS['cel_wp_actions']['wp_head'] = [
            2 => [[ 'feed_links' ]],
        ];

        $html = (string) $this->invokePrivateMethod($head, 'buildFeedLink');

        // コアに任せて二重出力しない。
        $this->assertSame('', $html);
    }

    public function test_build_feed_link_escapes_feed_url_and_title_attribute(): void
    {
        $options = new Options();
        $head    = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;
        $GLOBALS['cel_test_bloginfo']['name']     = '"><script>alert(1)</script>';
        $GLOBALS['cel_test_bloginfo']['rss2_url'] = 'https://example.org/feed/';
        $GLOBALS['cel_wp_actions']['wp_head']     = [];

        $html = (string) $this->invokePrivateMethod($head, 'buildFeedLink');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_build_verification_tags_escapes_attribute_value(): void
    {
        $options = new Options();
        // XSS を試みる細工された値。
        $options->set('cel_verify_google', '"><script>alert(1)</script>');

        $head = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));

        $GLOBALS['cel_test_conditionals']['is_front_page'] = true;

        $html = (string) $this->invokePrivateMethod($head, 'buildVerificationTags');

        // 記号 < / > / " が属性値内でエスケープされ、タグ境界を破れない。
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"><', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('google-site-verification', $html);
    }
}
