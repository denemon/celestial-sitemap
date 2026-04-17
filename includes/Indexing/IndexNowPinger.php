<?php

declare(strict_types=1);

namespace CelestialSitemap\Indexing;

use CelestialSitemap\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * IndexNow protocol client — notifies Bing / Yandex / Seznam / Naver of URL
 * changes as soon as a post is published. Google does not participate in
 * IndexNow, but the other engines honour the protocol.
 *
 * Responsibilities:
 *   - Lazily generate and persist a 32-character site key.
 *   - Serve that key at a fixed URL (`/cel-indexnow-key.txt`) so engines can
 *     verify ownership.
 *   - Ping the IndexNow endpoint non-blocking when a post transitions to
 *     `publish`, skipping revisions, autosaves, and posts marked noindex.
 *   - Debounce duplicate pings for the same URL within a short window.
 */
final class IndexNowPinger
{
    private const ENDPOINT         = 'https://api.indexnow.org/indexnow';
    private const KEY_FILE_SLUG    = 'cel-indexnow-key.txt';
    private const KEY_QUERY_VAR    = 'cel_indexnow_key';
    private const DEBOUNCE_SECONDS = 60;

    public function __construct(private Options $opts)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerRewrite']);
        add_filter('query_vars', [$this, 'addQueryVars']);
        add_action('template_redirect', [$this, 'maybeServeKeyFile'], 1);
        add_action('transition_post_status', [$this, 'handleTransition'], 10, 3);
    }

    public function registerRewrite(): void
    {
        add_rewrite_rule(
            '^' . preg_quote(self::KEY_FILE_SLUG, '/') . '/?$',
            'index.php?' . self::KEY_QUERY_VAR . '=1',
            'top'
        );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = self::KEY_QUERY_VAR;
        return $vars;
    }

    public function maybeServeKeyFile(): void
    {
        if ((int) get_query_var(self::KEY_QUERY_VAR) !== 1) {
            return;
        }
        $this->serveKeyFile();
    }

    /**
     * Returns the persisted IndexNow key, generating one on first use. Stable
     * for the lifetime of the site unless explicitly rotated.
     */
    public function ensureKey(): string
    {
        $existing = trim($this->opts->indexNowKey());
        if ($existing !== '') {
            return $existing;
        }

        $key = wp_generate_password(32, false, false);
        $this->opts->set('cel_indexnow_key', $key);
        return $key;
    }

    /**
     * Emit the key as plain text at the fixed verification endpoint.
     */
    public function serveKeyFile(): void
    {
        $key = $this->ensureKey();

        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('Cache-Control: public, max-age=86400');
        }

        echo $key;
        wp_die();
    }

    /**
     * Hook callback for `transition_post_status`. Fires once per state change.
     *
     * @param string    $new  New post status.
     * @param string    $old  Previous post status.
     * @param \stdClass $post Post object.
     */
    public function handleTransition(string $new, string $old, mixed $post): void
    {
        // Fire only when a post first enters the publish state.
        if ($new !== 'publish' || $old === 'publish') {
            return;
        }

        if (! is_object($post) || ! isset($post->ID)) {
            return;
        }

        $postId = (int) $post->ID;

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $registered = get_post_types(['public' => true], 'names');
        if (! in_array($post->post_type ?? '', $registered, true)) {
            return;
        }

        if (get_post_meta($postId, '_cel_noindex', true) === '1') {
            return;
        }

        $key = trim($this->opts->indexNowKey());
        if ($key === '') {
            return;
        }

        $permalink = (string) get_permalink($post);
        if ($permalink === '') {
            return;
        }

        $this->ping($permalink, $key);
    }

    private function ping(string $url, string $key): void
    {
        $debounceKey = 'cel_indexnow_ping_' . md5($url);
        if (get_transient($debounceKey) !== false) {
            return;
        }
        set_transient($debounceKey, 1, self::DEBOUNCE_SECONDS);

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return;
        }

        $payload = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . self::KEY_FILE_SLUG),
            'urlList'     => [$url],
        ];

        wp_remote_post(self::ENDPOINT, [
            'blocking' => false,
            'timeout'  => 2,
            'headers'  => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'     => wp_json_encode($payload),
        ]);
    }
}
