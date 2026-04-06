<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;

final class OptionsTest extends CelestialSitemap_TestCase
{
    public function test_returns_defaults_when_options_are_missing(): void
    {
        delete_option('cel_title_separator');
        delete_option('cel_homepage_title');
        delete_option('cel_noindex_author');
        delete_option('cel_noindex_date');
        delete_option('cel_sitemap_post_types');
        delete_option('cel_sitemap_taxonomies');
        delete_option('cel_schema_org');
        delete_option('cel_robots_txt_enabled');

        $options = new Options();

        // 未設定時はプラグインの既定値が返ることを確認する。
        $this->assertSame('|', $options->titleSeparator());
        $this->assertSame('', $options->homepageTitle());
        $this->assertTrue($options->noindexAuthor());
        $this->assertTrue($options->noindexDate());
        $this->assertSame(['post', 'page'], $options->sitemapPostTypes());
        $this->assertSame(['category'], $options->sitemapTaxonomies());
        $this->assertTrue($options->schemaEnabled());
        $this->assertFalse($options->robotsTxtEnabled());
    }

    public function test_returns_fallback_arrays_when_stored_values_are_invalid(): void
    {
        update_option('cel_sitemap_post_types', 'not-an-array');
        update_option('cel_sitemap_taxonomies', 'not-an-array');

        $options = new Options();

        // 配列で保持されていない壊れた値でも安全な既定値へ戻ることを確認する。
        $this->assertSame(['post', 'page'], $options->sitemapPostTypes());
        $this->assertSame(['category'], $options->sitemapTaxonomies());
    }

    public function test_set_updates_allowed_keys_and_cache_can_be_invalidated(): void
    {
        update_option('cel_homepage_title', 'Original title');

        $options = new Options();
        $this->assertSame('Original title', $options->homepageTitle());

        // 直接 DB を更新しても、キャッシュが有効な間は古い値を返す。
        update_option('cel_homepage_title', 'Changed in DB');
        $this->assertSame('Original title', $options->homepageTitle());

        // キャッシュ無効化後は DB の最新値が読まれる。
        $options->invalidateCache();
        $this->assertSame('Changed in DB', $options->homepageTitle());

        // 許可済みキーの setter は DB とメモリキャッシュの両方を更新する。
        $options->set('cel_homepage_title', 'Saved through setter');
        $this->assertSame('Saved through setter', get_option('cel_homepage_title'));
        $this->assertSame('Saved through setter', $options->homepageTitle());
    }

    public function test_set_rejects_unknown_keys(): void
    {
        $options = new Options();

        // 許可リスト外のキーは例外で弾かれるべき。
        $this->expectException(\InvalidArgumentException::class);
        $options->set('cel_unknown_key', 'value');
    }

    public function test_gsc_accessors_decrypt_encrypted_values(): void
    {
        if (! function_exists('openssl_encrypt')) {
            $this->markTestSkipped('openssl extension is required for encryption tests.');
        }

        update_option('cel_gsc_client_secret', Options::encrypt('client-secret'));
        update_option('cel_gsc_access_token', Options::encrypt('access-token'));

        $options = new Options();

        // getter が保存済み暗号文を平文へ戻して返すことを確認する。
        $this->assertSame('client-secret', $options->gscClientSecret());
        $this->assertSame('access-token', $options->gscAccessToken());
    }

    public function test_encrypt_and_decrypt_support_plaintext_and_round_trip(): void
    {
        if (! function_exists('openssl_encrypt')) {
            $this->markTestSkipped('openssl extension is required for encryption tests.');
        }

        $ciphertext = Options::encrypt('plain-value');

        // 暗号化した値は enc: 形式になり、復号で元に戻る。
        $this->assertStringStartsWith('enc:', $ciphertext);
        $this->assertSame('plain-value', Options::decrypt($ciphertext));

        // 既存の平文値は後方互換のためそのまま返す。
        $this->assertSame('legacy-plain', Options::decrypt('legacy-plain'));
    }
}
