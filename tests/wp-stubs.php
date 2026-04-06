<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/wp-root/');
}

if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/celestial-sitemap-tests/wp-content');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}

final class CelestialSitemapPreparedQuery
{
    public function __construct(
        public string $query,
        public array $args
    ) {
    }
}

final class CelestialSitemapFakeWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';

    /** @var array<string, array<int|string, mixed>> */
    private array $tables = [];

    /** @var array<string, string[]> */
    private array $schemas = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->tables = [
            $this->options => [],
        ];

        $this->schemas = [
            $this->options => ['option_name', 'option_value', 'autoload'],
        ];

        $this->autoIncrement = [
            $this->options => 1,
        ];
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, mixed ...$args): CelestialSitemapPreparedQuery
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return new CelestialSitemapPreparedQuery($query, $args);
    }

    /**
     * Minimal dbDelta emulation for the plugin tables used in tests.
     *
     * @return array<string, string>
     */
    public function dbDelta(string $sql): array
    {
        if (str_contains($sql, $this->prefix . 'cel_redirects')) {
            $table = $this->prefix . 'cel_redirects';
            $this->tables[$table] ??= [];
            $this->schemas[$table] = [
                'id',
                'source_url',
                'target_url',
                'status_code',
                'match_type',
                'hit_count',
                'created_at',
                'updated_at',
            ];
            $this->autoIncrement[$table] ??= 1;
        }

        if (str_contains($sql, $this->prefix . 'cel_404_log')) {
            $table = $this->prefix . 'cel_404_log';
            $this->tables[$table] ??= [];
            $this->schemas[$table] = [
                'id',
                'url',
                'referrer',
                'user_agent',
                'hit_count',
                'first_seen',
                'last_seen',
            ];
            $this->autoIncrement[$table] ??= 1;
        }

        return [];
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function get_var(mixed $query): mixed
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($template === 'SHOW TABLES LIKE %s') {
            $table = (string) ($args[0] ?? '');
            return $this->hasTable($table) ? $table : null;
        }

        if ($template === "SELECT option_value FROM {$this->options} WHERE option_name = %s LIMIT 1") {
            $name = (string) ($args[0] ?? '');
            return $this->tables[$this->options][$name]['option_value'] ?? null;
        }

        if ($sql === "SELECT COUNT(*) FROM {$this->prefix}cel_redirects") {
            return count($this->tables[$this->prefix . 'cel_redirects'] ?? []);
        }

        if ($sql === "SELECT COUNT(*) FROM {$this->prefix}cel_404_log") {
            return count($this->tables[$this->prefix . 'cel_404_log'] ?? []);
        }

        if ($template === "SELECT id FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $row['id'];
                }
            }

            return null;
        }

        if ($template === "SELECT target_url FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $row['target_url'];
                }
            }

            return null;
        }

        if ($template === "SELECT 1 FROM {$this->prefix}cel_404_log WHERE url = %s LIMIT 1") {
            $url = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_404_log'] ?? [] as $row) {
                if (($row['url'] ?? null) === $url) {
                    return 1;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>|object>
     */
    public function get_results(mixed $query, string|int $output = OBJECT): array
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($sql === "SELECT option_name, option_value FROM {$this->options} WHERE option_name LIKE 'cel\\_%'") {
            $rows = [];
            foreach ($this->tables[$this->options] as $name => $row) {
                if ($this->likeMatches('cel\\_%', (string) $name)) {
                    $rows[] = [
                        'option_name'  => $name,
                        'option_value' => $row['option_value'],
                    ];
                }
            }

            return $this->formatRows($rows, $output);
        }

        if ($sql === "SELECT * FROM {$this->prefix}cel_redirects") {
            return $this->formatRows(array_values($this->tables[$this->prefix . 'cel_redirects'] ?? []), $output);
        }

        if ($template === "SELECT * FROM {$this->prefix}cel_redirects ORDER BY updated_at DESC LIMIT %d OFFSET %d") {
            $rows = array_values($this->tables[$this->prefix . 'cel_redirects'] ?? []);
            usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

            return $this->formatRows(
                array_slice($rows, (int) ($args[1] ?? 0), (int) ($args[0] ?? count($rows))),
                $output
            );
        }

        if ($template === "SELECT * FROM {$this->prefix}cel_404_log ORDER BY hit_count DESC, last_seen DESC LIMIT %d OFFSET %d") {
            $rows = array_values($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            usort($rows, static function (array $a, array $b): int {
                $hitDiff = ((int) $b['hit_count']) <=> ((int) $a['hit_count']);
                if ($hitDiff !== 0) {
                    return $hitDiff;
                }

                return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
            });

            return $this->formatRows(
                array_slice($rows, (int) ($args[1] ?? 0), (int) ($args[0] ?? count($rows))),
                $output
            );
        }

        return [];
    }

    public function get_row(mixed $query, string|int $output = OBJECT): array|object|null
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($sql === "SELECT * FROM {$this->prefix}cel_404_log LIMIT 1") {
            $rows = array_values($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            $row = $rows[0] ?? null;

            return $row === null ? null : $this->formatRow($row, $output);
        }

        if ($template === "SELECT id, target_url, status_code FROM {$this->prefix}cel_redirects WHERE source_url = %s AND (match_type = 'exact' OR match_type IS NULL) LIMIT 1") {
            $source = (string) ($args[0] ?? '');

            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source && (($row['match_type'] ?? 'exact') === 'exact')) {
                    return $this->formatRow([
                        'id'          => $row['id'],
                        'target_url'  => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ], $output);
                }
            }

            return null;
        }

        if ($template === "SELECT id, target_url, status_code FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');

            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $this->formatRow([
                        'id'          => $row['id'],
                        'target_url'  => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ], $output);
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    public function get_col(mixed $query): array
    {
        [, , $sql] = $this->normalizeQuery($query);

        if ($sql === "SHOW COLUMNS FROM {$this->prefix}cel_redirects") {
            return $this->schemas[$this->prefix . 'cel_redirects'] ?? [];
        }

        return [];
    }

    public function query(mixed $query): int|false
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if (str_starts_with($sql, "DELETE FROM {$this->options} WHERE")) {
            preg_match_all("/option_name LIKE '([^']+)'/", $sql, $matches);
            $patterns = $matches[1] ?? [];
            $deleted = 0;

            foreach (array_keys($this->tables[$this->options]) as $name) {
                foreach ($patterns as $pattern) {
                    if ($this->likeMatches($pattern, (string) $name)) {
                        unset($this->tables[$this->options][$name]);
                        $deleted++;
                        break;
                    }
                }
            }

            return $deleted;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_redirects") {
            $count = count($this->tables[$this->prefix . 'cel_redirects'] ?? []);
            $this->tables[$this->prefix . 'cel_redirects'] = [];
            $this->autoIncrement[$this->prefix . 'cel_redirects'] = 1;

            return $count;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_404_log") {
            $count = count($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            $this->tables[$this->prefix . 'cel_404_log'] = [];
            $this->autoIncrement[$this->prefix . 'cel_404_log'] = 1;

            return $count;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_404_log WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)") {
            $threshold = time() - (90 * DAY_IN_SECONDS);
            $remaining = [];
            $deleted = 0;

            foreach ($this->tables[$this->prefix . 'cel_404_log'] ?? [] as $row) {
                $lastSeen = strtotime((string) ($row['last_seen'] ?? 'now'));
                if ($lastSeen !== false && $lastSeen < $threshold) {
                    $deleted++;
                    continue;
                }

                $remaining[] = $row;
            }

            $this->tables[$this->prefix . 'cel_404_log'] = $remaining;

            return $deleted;
        }

        if ($template === "UPDATE {$this->prefix}cel_redirects SET hit_count = hit_count + 1 WHERE id = %d") {
            $id = (int) ($args[0] ?? 0);

            if (! isset($this->tables[$this->prefix . 'cel_redirects'])) {
                return 0;
            }

            foreach ($this->tables[$this->prefix . 'cel_redirects'] as &$row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    $row['hit_count'] = (int) ($row['hit_count'] ?? 0) + 1;
                    $row['updated_at'] = gmdate('Y-m-d H:i:s');
                    return 1;
                }
            }
            unset($row);

            return 0;
        }

        if (str_starts_with($template, "INSERT INTO {$this->prefix}cel_404_log ")) {
            $url = (string) ($args[0] ?? '');
            $referrer = (string) ($args[1] ?? '');
            $userAgent = (string) ($args[2] ?? '');
            $now = gmdate('Y-m-d H:i:s');

            $this->tables[$this->prefix . 'cel_404_log'] ??= [];

            foreach ($this->tables[$this->prefix . 'cel_404_log'] as &$row) {
                if (($row['url'] ?? null) === $url) {
                    $row['hit_count'] = (int) ($row['hit_count'] ?? 0) + 1;
                    $row['last_seen'] = $now;
                    $row['referrer'] = $referrer;
                    $row['user_agent'] = $userAgent;
                    return 1;
                }
            }
            unset($row);

            $this->tables[$this->prefix . 'cel_404_log'][] = [
                'id'         => $this->nextId($this->prefix . 'cel_404_log'),
                'url'        => $url,
                'referrer'   => $referrer,
                'user_agent' => $userAgent,
                'hit_count'  => 1,
                'first_seen' => $now,
                'last_seen'  => $now,
            ];

            return 1;
        }

        if ($sql === "ALTER TABLE {$this->prefix}cel_404_log AUTO_INCREMENT = 1") {
            $rows = $this->tables[$this->prefix . 'cel_404_log'] ?? [];
            $max = 0;
            foreach ($rows as $row) {
                $max = max($max, (int) ($row['id'] ?? 0));
            }

            $this->autoIncrement[$this->prefix . 'cel_404_log'] = $max + 1;
            return 0;
        }

        return false;
    }

    public function insert(string $table, array $data, array $formats = []): int|false
    {
        if ($table === $this->prefix . 'cel_redirects') {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'id'          => $this->nextId($table),
                'source_url'  => (string) ($data['source_url'] ?? ''),
                'target_url'  => (string) ($data['target_url'] ?? ''),
                'status_code' => (int) ($data['status_code'] ?? 301),
                'match_type'  => $data['match_type'] ?? 'exact',
                'hit_count'   => (int) ($data['hit_count'] ?? 0),
                'created_at'  => (string) ($data['created_at'] ?? $now),
                'updated_at'  => (string) ($data['updated_at'] ?? $now),
            ];

            $this->tables[$table][] = $row;
            return 1;
        }

        if ($table === $this->prefix . 'cel_404_log') {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'id'         => $this->nextId($table),
                'url'        => (string) ($data['url'] ?? ''),
                'referrer'   => (string) ($data['referrer'] ?? ''),
                'user_agent' => (string) ($data['user_agent'] ?? ''),
                'hit_count'  => (int) ($data['hit_count'] ?? 1),
                'first_seen' => (string) ($data['first_seen'] ?? $now),
                'last_seen'  => (string) ($data['last_seen'] ?? $now),
            ];

            $this->tables[$table][] = $row;
            return 1;
        }

        return false;
    }

    public function delete(string $table, array $where, array $whereFormats = []): int|false
    {
        $rows = $this->tables[$table] ?? null;
        if ($rows === null) {
            return false;
        }

        $remaining = [];
        $deleted = 0;

        foreach ($rows as $row) {
            $matches = true;
            foreach ($where as $column => $value) {
                if (($row[$column] ?? null) != $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $deleted++;
                continue;
            }

            $remaining[] = $row;
        }

        $this->tables[$table] = $remaining;

        return $deleted;
    }

    public function getOption(string $name): mixed
    {
        return $this->tables[$this->options][$name]['option_value'] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->tables[$this->options]);
    }

    public function addOption(string $name, mixed $value, bool $autoload = false): bool
    {
        if ($this->hasOption($name)) {
            return false;
        }

        $this->tables[$this->options][$name] = [
            'option_name'  => $name,
            'option_value' => $value,
            'autoload'     => $autoload ? 'yes' : 'no',
        ];

        return true;
    }

    public function updateOption(string $name, mixed $value, bool|string|null $autoload = null): bool
    {
        $existingAutoload = $this->tables[$this->options][$name]['autoload'] ?? 'no';

        $this->tables[$this->options][$name] = [
            'option_name'  => $name,
            'option_value' => $value,
            'autoload'     => is_bool($autoload) ? ($autoload ? 'yes' : 'no') : $existingAutoload,
        ];

        return true;
    }

    public function deleteOption(string $name): bool
    {
        if (! $this->hasOption($name)) {
            return false;
        }

        unset($this->tables[$this->options][$name]);

        return true;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>, 2: string}
     */
    private function normalizeQuery(mixed $query): array
    {
        if ($query instanceof CelestialSitemapPreparedQuery) {
            return [
                $this->normalizeSql($query->query),
                $query->args,
                $this->normalizeSql($query->query),
            ];
        }

        return [
            $this->normalizeSql((string) $query),
            [],
            $this->normalizeSql((string) $query),
        ];
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }

    private function nextId(string $table): int
    {
        $next = $this->autoIncrement[$table] ?? 1;
        $this->autoIncrement[$table] = $next + 1;

        return $next;
    }

    private function likeMatches(string $pattern, string $value): bool
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && ($i + 1) < $length) {
                $regex .= preg_quote($pattern[$i + 1], '/');
                $i++;
                continue;
            }

            if ($char === '%') {
                $regex .= '.*';
                continue;
            }

            if ($char === '_') {
                $regex .= '.';
                continue;
            }

            $regex .= preg_quote($char, '/');
        }

        return (bool) preg_match('/^' . $regex . '$/', $value);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>|object>
     */
    private function formatRows(array $rows, string|int $output): array
    {
        return array_map(fn(array $row): array|object => $this->formatRow($row, $output), $rows);
    }

    private function formatRow(array $row, string|int $output): array|object
    {
        if ($output === ARRAY_A) {
            return $row;
        }

        return (object) $row;
    }
}

final class CelestialSitemapFakeWpQuery
{
    public bool $is_404 = false;

    public function set_404(): void
    {
        $this->is_404 = true;
    }
}

abstract class WP_UnitTestCase extends TestCase
{
    public static function set_up_before_class(): void
    {
    }

    public static function tear_down_after_class(): void
    {
    }

    protected function set_up(): void
    {
    }

    protected function tear_down(): void
    {
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::set_up_before_class();
    }

    public static function tearDownAfterClass(): void
    {
        static::tear_down_after_class();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up();
    }

    protected function tearDown(): void
    {
        try {
            $this->tear_down();
        } finally {
            parent::tearDown();
        }
    }

    protected function go_to(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $GLOBALS['wp_query'] = new CelestialSitemapFakeWpQuery();
    }
}

function celestial_sitemap_test_bootstrap(): void
{
    global $wpdb;

    if (! isset($wpdb) || ! ($wpdb instanceof CelestialSitemapFakeWpdb)) {
        $wpdb = new CelestialSitemapFakeWpdb();
    }

    $GLOBALS['wp_query'] = new CelestialSitemapFakeWpQuery();
    $GLOBALS['cel_wp_actions'] = [];
    $GLOBALS['cel_wp_filters'] = [];
    $GLOBALS['cel_wp_activation_hooks'] = [];
    $GLOBALS['cel_wp_deactivation_hooks'] = [];
    $GLOBALS['cel_wp_cron'] = [];
    $GLOBALS['cel_last_redirect'] = null;
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . DIRECTORY_SEPARATOR;
}

function plugin_dir_url(string $file): string
{
    return 'http://example.org/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function register_activation_hook(string $file, callable $callback): void
{
    $GLOBALS['cel_wp_activation_hooks'][$file] = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void
{
    $GLOBALS['cel_wp_deactivation_hooks'][$file] = $callback;
}

function add_action(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cel_wp_actions'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function add_filter(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cel_wp_filters'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function tests_add_filter(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    add_filter($hook, $callback, $priority, $acceptedArgs);
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string $pluginRelPath = ''): bool
{
    return true;
}

function is_admin(): bool
{
    return false;
}

function __return_false(): bool
{
    return false;
}

function __return_true(): bool
{
    return true;
}

function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
{
}

function flush_rewrite_rules(bool $hard = true): void
{
}

function wp_cache_flush(): void
{
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['cel_wp_cron'][$hook][$timestamp] = ['recurrence' => $recurrence];
    ksort($GLOBALS['cel_wp_cron'][$hook]);

    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook): bool
{
    $GLOBALS['cel_wp_cron'][$hook][$timestamp] = ['recurrence' => false];
    ksort($GLOBALS['cel_wp_cron'][$hook]);

    return true;
}

function wp_next_scheduled(string $hook): int|false
{
    if (empty($GLOBALS['cel_wp_cron'][$hook])) {
        return false;
    }

    $timestamps = array_keys($GLOBALS['cel_wp_cron'][$hook]);
    sort($timestamps);

    return $timestamps[0] ?? false;
}

function wp_unschedule_event(int $timestamp, string $hook): bool
{
    if (! isset($GLOBALS['cel_wp_cron'][$hook][$timestamp])) {
        return false;
    }

    unset($GLOBALS['cel_wp_cron'][$hook][$timestamp]);

    if ($GLOBALS['cel_wp_cron'][$hook] === []) {
        unset($GLOBALS['cel_wp_cron'][$hook]);
    }

    return true;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', bool $autoload = true): bool
{
    global $wpdb;

    return $wpdb->addOption($option, $value, $autoload);
}

function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
{
    global $wpdb;

    return $wpdb->updateOption($option, $value, $autoload);
}

function get_option(string $option, mixed $default = false): mixed
{
    global $wpdb;

    return $wpdb->hasOption($option) ? $wpdb->getOption($option) : $default;
}

function delete_option(string $option): bool
{
    global $wpdb;

    return $wpdb->deleteOption($option);
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    update_option('_transient_' . $transient, $value);

    if ($expiration > 0) {
        update_option('_transient_timeout_' . $transient, time() + $expiration);
    } else {
        delete_option('_transient_timeout_' . $transient);
    }

    return true;
}

function get_transient(string $transient): mixed
{
    $timeout = get_option('_transient_timeout_' . $transient, null);

    if (is_int($timeout) && $timeout > 0 && $timeout < time()) {
        delete_transient($transient);
        return false;
    }

    return get_option('_transient_' . $transient, false);
}

function delete_transient(string $transient): bool
{
    $deleted = delete_option('_transient_' . $transient);
    delete_option('_transient_timeout_' . $transient);

    return $deleted;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    if (func_num_args() === 1) {
        return parse_url($url);
    }

    return parse_url($url, $component);
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function sanitize_text_field(mixed $value): string
{
    $string = is_scalar($value) ? (string) $value : '';
    $string = strip_tags($string);

    return trim(preg_replace('/[\r\n\t ]+/', ' ', $string) ?? $string);
}

function esc_url_raw(string $url): string
{
    return trim($url);
}

function wp_get_referer(): string|false
{
    return $_SERVER['HTTP_REFERER'] ?? false;
}

function current_time(string $type, bool $gmt = false): string|int
{
    if ($type === 'mysql') {
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }

    return time();
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'celestial-sitemap-test-salt-' . $scheme;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_current_blog_id(): int
{
    return 1;
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0777, true);
}

function is_404(): bool
{
    return ! empty($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof CelestialSitemapFakeWpQuery
        && $GLOBALS['wp_query']->is_404;
}

function wp_redirect(string $location, int $status = 302): bool
{
    $GLOBALS['cel_last_redirect'] = [
        'location' => $location,
        'status'   => $status,
    ];

    return true;
}

function maybe_unserialize(mixed $value): mixed
{
    if (! is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === 'b:0;' || preg_match('/^(?:a|O|s|i|d|b):/', $trimmed)) {
        $unserialized = @unserialize($trimmed);
        if ($unserialized !== false || $trimmed === 'b:0;') {
            return $unserialized;
        }
    }

    return $value;
}

function dbDelta(string $sql): array
{
    global $wpdb;

    return $wpdb->dbDelta($sql);
}

if (! function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

celestial_sitemap_test_bootstrap();
