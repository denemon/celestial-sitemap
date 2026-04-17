<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Centralised, typed access to plugin options.
 *
 * All cel_* options are bulk-loaded in a single DB query on first access
 * and cached for the duration of the request. This eliminates per-option
 * DB hits for non-autoloaded options.
 *
 * Frequently-used options are also set to autoload=true (see Activator).
 */
final class Options
{
    /**
     * In-memory cache of all cel_* options, keyed by option name.
     * Populated lazily on first access via loadAll().
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * Options that are allowed to be set via the generic setter.
     *
     * @var string[]
     */
    private const ALLOWED_KEYS = [
        'cel_title_separator',
        'cel_homepage_title',
        'cel_homepage_desc',
        'cel_noindex_archives',
        'cel_noindex_tags',
        'cel_noindex_author',
        'cel_noindex_date',
        'cel_noindex_search',
        'cel_sitemap_post_types',
        'cel_sitemap_taxonomies',
        'cel_schema_org',
        'cel_schema_org_type',
        'cel_schema_org_name',
        'cel_schema_org_logo',
        'cel_ogp_enabled',
        'cel_ogp_default_image',
        'cel_breadcrumbs',
        'cel_indexnow_key',
        'cel_fb_app_id',
        'cel_twitter_site',
        'cel_robots_txt_enabled',
        'cel_robots_txt_content',
        'cel_verify_google',
        'cel_verify_bing',
        'cel_verify_yandex',
        'cel_verify_baidu',
        'cel_verify_naver',
        'cel_verify_pinterest',
    ];

    /**
     * Supported search engine verification providers.
     * Value is the option key suffix; key is the provider slug used at the
     * HeadManager output site.
     */
    public const VERIFICATION_PROVIDERS = [
        'google',
        'bing',
        'yandex',
        'baidu',
        'naver',
        'pinterest',
    ];

    // ── Bulk loader ─────────────────────────────────────────────────

    /**
     * Load all cel_* options in a single query and cache them.
     */
    private function loadAll(): void
    {
        if ($this->cache !== null) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'cel\_%'",
            ARRAY_A
        );

        $this->cache = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $this->cache[$row['option_name']] = maybe_unserialize($row['option_value']);
            }
        }
    }

    /**
     * Get a cached option value with fallback default.
     */
    private function get(string $key, mixed $default = ''): mixed
    {
        $this->loadAll();
        return $this->cache[$key] ?? $default;
    }

    // ── Title / Meta ─────────────────────────────────────────────────
    public function titleSeparator(): string
    {
        return (string) $this->get('cel_title_separator', '|');
    }

    public function homepageTitle(): string
    {
        return (string) $this->get('cel_homepage_title', '');
    }

    public function homepageDesc(): string
    {
        return (string) $this->get('cel_homepage_desc', '');
    }

    // ── Noindex ──────────────────────────────────────────────────────
    public function noindexArchives(): bool
    {
        return (bool) $this->get('cel_noindex_archives', false);
    }

    public function noindexTags(): bool
    {
        return (bool) $this->get('cel_noindex_tags', false);
    }

    public function noindexAuthor(): bool
    {
        return (bool) $this->get('cel_noindex_author', true);
    }

    public function noindexDate(): bool
    {
        return (bool) $this->get('cel_noindex_date', true);
    }

    public function noindexSearch(): bool
    {
        return (bool) $this->get('cel_noindex_search', true);
    }

    // ── Robots.txt ──────────────────────────────────────────────────
    public function robotsTxtEnabled(): bool
    {
        return (bool) $this->get('cel_robots_txt_enabled', false);
    }

    public function robotsTxtContent(): string
    {
        return (string) $this->get('cel_robots_txt_content', '');
    }

    // ── Sitemap ──────────────────────────────────────────────────────
    /** @return string[] */
    public function sitemapPostTypes(): array
    {
        $val = $this->get('cel_sitemap_post_types', ['post', 'page']);
        return is_array($val) ? $val : ['post', 'page'];
    }

    /** @return string[] */
    public function sitemapTaxonomies(): array
    {
        $val = $this->get('cel_sitemap_taxonomies', ['category']);
        return is_array($val) ? $val : ['category'];
    }

    // ── Schema ───────────────────────────────────────────────────────
    public function schemaEnabled(): bool
    {
        return (bool) $this->get('cel_schema_org', true);
    }

    public function schemaOrgType(): string
    {
        return (string) $this->get('cel_schema_org_type', 'Organization');
    }

    public function schemaOrgName(): string
    {
        return (string) $this->get('cel_schema_org_name', '');
    }

    public function schemaOrgLogo(): string
    {
        return (string) $this->get('cel_schema_org_logo', '');
    }

    // ── OGP ──────────────────────────────────────────────────────────
    public function ogpEnabled(): bool
    {
        return (bool) $this->get('cel_ogp_enabled', true);
    }

    public function ogpDefaultImage(): string
    {
        return (string) $this->get('cel_ogp_default_image', '');
    }

    // ── Breadcrumbs ──────────────────────────────────────────────────
    public function breadcrumbsEnabled(): bool
    {
        return (bool) $this->get('cel_breadcrumbs', true);
    }

    // ── Social ───────────────────────────────────────────────────────
    public function fbAppId(): string
    {
        return (string) $this->get('cel_fb_app_id', '');
    }

    public function twitterSite(): string
    {
        return (string) $this->get('cel_twitter_site', '');
    }

    // ── Search engine verification ──────────────────────────────────
    /**
     * Return the verification code for a given provider slug.
     * Unknown providers return an empty string so the caller can treat it
     * as "disabled" without a conditional.
     */
    public function verificationCode(string $provider): string
    {
        if (! in_array($provider, self::VERIFICATION_PROVIDERS, true)) {
            return '';
        }
        return (string) $this->get('cel_verify_' . $provider, '');
    }

    // ── IndexNow ─────────────────────────────────────────────────────
    public function indexNowKey(): string
    {
        return (string) $this->get('cel_indexnow_key', '');
    }

    // ── Generic setter ───────────────────────────────────────────────

    /**
     * Set an option value. Only keys in the ALLOWED_KEYS list are accepted.
     *
     * @throws \InvalidArgumentException If the key is not in the allowed list.
     */
    public function set(string $key, mixed $value): void
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Option key "%s" is not in the allowed list.', $key)
            );
        }

        update_option($key, $value);

        // Update in-memory cache
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /**
     * Invalidate the in-memory cache so the next access reloads from DB.
     */
    public function invalidateCache(): void
    {
        $this->cache = null;
    }
}
