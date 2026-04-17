<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;
use CelestialSitemap\Indexing\IndexNowPinger;

final class IndexNowPingerTest extends CelestialSitemap_TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $GLOBALS['cel_test_http_calls'] = [];
        $GLOBALS['cel_test_is_revision'] = [];
        $GLOBALS['cel_test_is_autosave'] = [];
    }

    public function test_ensure_key_generates_and_persists_32_char_key_once(): void
    {
        $pinger = new IndexNowPinger(new Options());

        $key = $pinger->ensureKey();

        // 生成された鍵は 32 文字 の英数字。
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{32}$/', $key);
        $this->assertSame($key, get_option('cel_indexnow_key'));

        // 再呼び出しでも同じ値を返す（再生成しない）。
        $this->assertSame($key, $pinger->ensureKey());
    }

    public function test_key_file_endpoint_returns_stored_key_as_plaintext(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'test-key-abc123');

        $pinger = new IndexNowPinger($options);

        ob_start();
        try {
            $pinger->serveKeyFile();
            $this->fail('serveKeyFile should halt via exit sentinel.');
        } catch (CelestialSitemapAjaxExit) {
        }
        $body = (string) ob_get_clean();

        $this->assertSame('test-key-abc123', $body);
    }

    public function test_handle_transition_pings_indexnow_on_publish(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'abc123def456ghi789jkl012mno345pq');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $post = (object) [
            'ID'          => 101,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/new-post/',
        ];
        $GLOBALS['cel_test_posts'][101] = $post;

        $pinger = new IndexNowPinger($options);
        $pinger->handleTransition('publish', 'draft', $post);

        $this->assertCount(1, $GLOBALS['cel_test_http_calls']);
        $call = $GLOBALS['cel_test_http_calls'][0];

        $this->assertSame('POST', $call['method']);
        $this->assertStringStartsWith('https://api.indexnow.org/', $call['url']);
        $this->assertFalse($call['args']['blocking'] ?? true, 'call must be non-blocking');

        $payload = json_decode((string) ($call['args']['body'] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame('example.org', $payload['host']);
        $this->assertSame('abc123def456ghi789jkl012mno345pq', $payload['key']);
        $this->assertSame(['https://example.org/new-post/'], $payload['urlList']);
        $this->assertSame(
            'https://example.org/cel-indexnow-key.txt',
            $payload['keyLocation']
        );
    }

    public function test_handle_transition_skips_non_publish_transitions(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'abc123def456ghi789jkl012mno345pq');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $post = (object) [
            'ID'          => 102,
            'post_type'   => 'post',
            'post_status' => 'draft',
            'permalink'   => 'https://example.org/draft/',
        ];
        $GLOBALS['cel_test_posts'][102] = $post;

        $pinger = new IndexNowPinger($options);
        $pinger->handleTransition('draft', 'auto-draft', $post);
        $pinger->handleTransition('trash', 'publish', $post);

        // 非公開遷移では ping を打たない。
        $this->assertSame([], $GLOBALS['cel_test_http_calls']);
    }

    public function test_handle_transition_skips_revisions_and_autosaves(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'abc123def456ghi789jkl012mno345pq');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $revision = (object) [
            'ID'          => 201,
            'post_type'   => 'revision',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/?p=201',
        ];
        $GLOBALS['cel_test_posts'][201] = $revision;
        $GLOBALS['cel_test_is_revision'][201] = 101;

        $autosave = (object) [
            'ID'          => 202,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/?p=202',
        ];
        $GLOBALS['cel_test_posts'][202] = $autosave;
        $GLOBALS['cel_test_is_autosave'][202] = 101;

        $pinger = new IndexNowPinger($options);
        // 'draft' → 'publish' のエッジでも、revision/autosave は ping から除外する。
        $pinger->handleTransition('publish', 'draft', $revision);
        $pinger->handleTransition('publish', 'draft', $autosave);

        $this->assertSame([], $GLOBALS['cel_test_http_calls']);
    }

    public function test_handle_transition_skips_noindex_posts(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'abc123def456ghi789jkl012mno345pq');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $post = (object) [
            'ID'          => 103,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/private/',
        ];
        $GLOBALS['cel_test_posts'][103] = $post;
        $GLOBALS['cel_test_post_meta'][103]['_cel_noindex'] = '1';

        $pinger = new IndexNowPinger($options);
        $pinger->handleTransition('publish', 'draft', $post);

        $this->assertSame([], $GLOBALS['cel_test_http_calls']);
    }

    public function test_handle_transition_debounces_duplicate_pings_for_same_url(): void
    {
        $options = new Options();
        $options->set('cel_indexnow_key', 'abc123def456ghi789jkl012mno345pq');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $post = (object) [
            'ID'          => 104,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/same-url/',
        ];
        $GLOBALS['cel_test_posts'][104] = $post;

        $pinger = new IndexNowPinger($options);
        // 短時間に同じ URL が draft→publish を二回繰り返しても、
        // IndexNow へは一回だけ通知する（WP が hook を多重に発火させる既知の挙動への耐性）。
        $pinger->handleTransition('publish', 'draft', $post);
        $pinger->handleTransition('publish', 'draft', $post);

        $this->assertCount(1, $GLOBALS['cel_test_http_calls']);
    }

    public function test_handle_transition_is_noop_when_key_is_empty(): void
    {
        $options = new Options();
        // 明示的に未設定。
        $options->set('cel_indexnow_key', '');
        $GLOBALS['cel_test_registered_post_types'] = ['post'];

        $post = (object) [
            'ID'          => 105,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'permalink'   => 'https://example.org/any/',
        ];
        $GLOBALS['cel_test_posts'][105] = $post;

        $pinger = new IndexNowPinger($options);
        $pinger->handleTransition('publish', 'draft', $post);

        $this->assertSame([], $GLOBALS['cel_test_http_calls']);
    }
}
