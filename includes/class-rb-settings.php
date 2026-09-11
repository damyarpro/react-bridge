<?php
if (!defined('ABSPATH')) exit;

/**
 * Settings store for React Bridge.
 *
 * - all() always returns typed values (bool/int/array/string), whatever is stored.
 * - The 7 CONTRACT_KEYS are the blog-settings product contract shared by the REST
 *   endpoint (RB_Settings_Rest) and the admin form; validate() is the one rule set.
 * - migrate() upgrades schema 1 (legacy keys) once; afterwards it is a constant-time
 *   check against the autoloaded option.
 * - cache_gen() is part of every server-cache key, so bump_cache_gen() invalidates all
 *   cached responses without scanning the options table.
 */
final class RB_Settings
{
    const OPTION        = 'rb_settings';
    /** Autoloaded flag: the admin finished (or dismissed) the quick-start wizard. */
    const ONBOARDING_OPTION = 'rb_onboarding_done';
    const SCHEMA        = 2;
    const CONTRACT_KEYS = ['frontend_url', 'blog_path', 'redirect_to_frontend', 'cors_origins', 'cache_ttl_seconds', 'posts_per_page', 'revalidate_webhook_url'];

    const GEN_OPTION   = 'rb_cache_gen';
    const MAX_ORIGINS  = 50;
    const MAX_TTL      = 86400;
    const MAX_PER_PAGE = 50;

    /** Schema 1 key => schema 2 key. */
    private const LEGACY = [
        'redirect_wp'    => 'redirect_to_frontend',
        'origins'        => 'cors_origins',
        'cache_ttl'      => 'cache_ttl_seconds',
        'per_page'       => 'posts_per_page',
        'revalidate_url' => 'revalidate_webhook_url',
    ];

    /** Longest accepted panel_name; the field is a label, not content. */
    const MAX_PANEL_NAME = 60;

    private static ?array $cache = null;

    /**
     * Validation messages. Built in a method because a class constant cannot call __().
     * Keys are stable; only the text is translated.
     */
    public static function msg(string $key): string
    {
        $messages = [
            'frontend_url'           => __('The frontend URL must be empty or a full http or https address.', 'react-bridge'),
            'frontend_slash'         => __('The frontend URL must not end with a slash.', 'react-bridge'),
            'frontend_parts'         => __('The frontend URL must not contain ?, # or a user name.', 'react-bridge'),
            'blog_path'              => __('The blog path must look like /blog: it starts with a slash and has no trailing slash, space, ? or #.', 'react-bridge'),
            'redirect_to_frontend'   => __('The redirect value must be true or false.', 'react-bridge'),
            'cors_origins'           => __('Allowed origins must be a list of strings.', 'react-bridge'),
            'cors_star'              => __('The * wildcard is not allowed. Add each origin separately.', 'react-bridge'),
            /* translators: %s: the origin that was rejected. */
            'cors_item'              => __('The origin "%s" is not valid. Use scheme://host[:port] without a path.', 'react-bridge'),
            'cors_max'               => __('At most 50 origins are allowed.', 'react-bridge'),
            'cache_ttl_seconds'      => __('The cache lifetime must be a whole number between 0 and 86400 seconds.', 'react-bridge'),
            'posts_per_page'         => __('Posts per page must be a whole number between 1 and 50.', 'react-bridge'),
            'revalidate_webhook_url' => __('The webhook URL must be empty or a full http or https address.', 'react-bridge'),
            'panel_url'              => __('The panel URL must be empty or a full http or https address.', 'react-bridge'),
            'locale'                 => __('The language is not valid. For example fa_IR.', 'react-bridge'),
            'required'               => __('This field is required.', 'react-bridge'),
        ];
        return $messages[$key] ?? $key;
    }

    public static function init(): void
    {
        // Any writer (this class, options.php, other plugins) invalidates the per-request copy.
        $reset = static function (): void { self::$cache = null; };
        foreach (['add_option_', 'update_option_', 'delete_option_'] as $hook) add_action($hook . self::OPTION, $reset);

        if (did_action('plugins_loaded')) self::migrate();
        else add_action('plugins_loaded', [self::class, 'migrate'], 1);

        add_action('init', static function (): void { self::ensure_secret(); });
    }

    public static function defaults(): array
    {
        return [
            'frontend_url'           => '',
            'blog_path'              => '/blog',
            'redirect_to_frontend'   => false,
            'cors_origins'           => [],
            'cache_ttl_seconds'      => 300,
            'posts_per_page'         => 10,
            'revalidate_webhook_url' => '',
            'require_key'            => false,
            'api_key'                => '',
            'site_name'              => (string) get_bloginfo('name'),
            'seo_title_tpl'          => '%title% | %site%',
            'default_desc'           => (string) get_bloginfo('description'),
            'default_og'             => '',
            'twitter'                => '',
            'locale'                 => (string) get_locale(),
            'expose_pages'           => true,
            // Optional external admin panel that edits the blog settings through the REST contract.
            'panel_name'             => '',
            'panel_url'              => '',
        ];
    }

    public static function install(): void
    {
        $stored = get_option(self::OPTION);
        if (!is_array($stored)) {
            $stored = self::defaults();
            $stored['api_key'] = self::new_api_key();
            $stored['_schema'] = self::SCHEMA;
            update_option(self::OPTION, $stored);
        } elseif (empty($stored['api_key'])) {
            $stored['api_key'] = self::new_api_key();
            update_option(self::OPTION, $stored);
        }
        self::ensure_secret();
        add_option(self::GEN_OPTION, 1, '', true);
        self::$cache = null;
    }

    /** Idempotent schema 1 > 2 upgrade. After the first run this is one autoloaded read + an int compare. */
    public static function migrate(): void
    {
        $raw = get_option(self::OPTION);
        if (!is_array($raw) || (int) ($raw['_schema'] ?? 0) >= self::SCHEMA) return;

        $new = self::normalize($raw);
        $new['_schema'] = self::SCHEMA;
        // The stored value always differs (it has no _schema yet), so false means the write failed.
        if (!update_option(self::OPTION, $new)) {
            error_log('[react-bridge] settings migration to schema ' . self::SCHEMA . ' could not be saved; it will be retried on the next request.');
            return;
        }
        self::$cache = null;
        self::bump_cache_gen();
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = self::normalize(is_array($stored) ? $stored : []);
        }
        return self::$cache;
    }

    public static function get(string $key, $fallback = null)
    {
        return self::all()[$key] ?? $fallback;
    }

    public static function contract(): array
    {
        $all = self::all();
        $out = [];
        foreach (self::CONTRACT_KEYS as $key) $out[$key] = $all[$key];
        return $out;
    }

    /**
     * Payload for an external admin panel's blog-settings form: the 7 contract keys plus how to reach
     * this WordPress, flat and with the panel's field names so it can be pasted there or PATCHed as is.
     * Never contains rb_secret; the API key is included only when it is required (it is public anyway).
     */
    public static function panel_export(): array
    {
        $all = self::all();
        return self::contract() + [
            'wordpress_enabled' => true,
            'wordpress_api_url' => untrailingslashit(rest_url(RB_NS)),
            'wordpress_api_key' => $all['require_key'] ? (string) $all['api_key'] : '',
        ];
    }


    /**
     * Is the plugin connected to a real React frontend?
     *
     * The flag alone is not enough: a site can be "done" and later be pointed back at WordPress
     * itself (or at a /wp-json URL), which is never a working headless setup. In that case the
     * wizard reappears so the admin can fix it, without losing the stored flag.
     *
     * @return array{done: bool, reasons: string[]}
     */
    public static function setup_state(): array
    {
        $frontend = trim((string) self::get('frontend_url', ''));
        $reasons  = [];

        if ($frontend === '') {
            $reasons[] = 'frontend_missing';
        } else {
            $same = self::origin_of($frontend) !== null && self::origin_of($frontend) === self::origin_of((string) home_url());
            if ($same || str_contains($frontend, '/wp-json')) $reasons[] = 'frontend_is_wordpress';
        }

        return [
            'done'    => $reasons === [] && (bool) get_option(self::ONBOARDING_OPTION, false),
            'reasons' => $reasons,
        ];
    }

    public static function set_onboarding_done(bool $done): void
    {
        update_option(self::ONBOARDING_OPTION, $done ? 1 : 0, true);
    }

    /**
     * Strict validation of typed (JSON) input against the contract.
     *
     * @return array{0: array, 1: array<string,string>, 2: string[]} [$clean, $errors field => message, $ignored unknown keys]
     */
    public static function validate(array $input, bool $partial = true): array
    {
        $clean = $errors = $ignored = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, self::CONTRACT_KEYS, true)) {
                $ignored[] = $key;
                continue;
            }
            [$v, $error] = self::check($key, $value);
            if ($error !== null) $errors[$key] = $error;
            else $clean[$key] = $v;
        }
        if (!$partial) {
            foreach (self::CONTRACT_KEYS as $key) {
                if (!array_key_exists($key, $input)) $errors[$key] = self::msg('required');
            }
        }
        return [$clean, $errors, $ignored];
    }

    /** Merge already-validated values into the stored option. Unknown keys are dropped; values are type-coerced. */
    public static function update(array $clean): void
    {
        $stored   = get_option(self::OPTION);
        $current  = self::normalize(is_array($stored) ? $stored : []);
        $defaults = self::defaults();
        foreach ($clean as $key => $value) {
            if (array_key_exists($key, $defaults)) $current[$key] = self::coerce((string) $key, $value, $defaults[$key]);
        }
        $current['_schema'] = self::SCHEMA;
        update_option(self::OPTION, $current);
        self::$cache = null;
        self::bump_cache_gen();
    }

    /**
     * register_setting() sanitize callback for the admin form.
     *
     * Receives raw form strings, but WordPress also runs it on every update_option() of this
     * option while admin_init has fired (e.g. AJAX), so typed arrays must pass through unchanged.
     */
    public static function sanitize_form($in): array
    {
        $in  = is_array($in) ? $in : [];
        $out = self::all();

        // Contract keys: convert form strings to typed values, then apply the same rules as the REST API.
        $typed = ['redirect_to_frontend' => !empty($in['redirect_to_frontend'])];
        // RTL copy-paste injects invisible bidi/zero-width marks; the form strips them, the REST API rejects them.
        foreach (['frontend_url', 'blog_path', 'revalidate_webhook_url'] as $k) {
            if (array_key_exists($k, $in)) $typed[$k] = is_string($in[$k]) ? trim(self::strip_invisible($in[$k])) : $in[$k];
        }
        if (array_key_exists('cors_origins', $in)) {
            $list = is_string($in['cors_origins']) ? preg_split('/[\r\n,]+/', $in['cors_origins']) : $in['cors_origins'];
            if (is_array($list)) {
                $list = array_values(array_filter(array_map(fn($o) => is_string($o) ? trim(self::strip_invisible($o)) : $o, $list), fn($o) => $o !== ''));
            }
            $typed['cors_origins'] = $list;
        }
        foreach (['cache_ttl_seconds', 'posts_per_page'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $v = $in[$k];
            $typed[$k] = is_string($v) && preg_match('/^\s*-?\d{1,9}\s*$/', $v) ? (int) trim($v) : $v;
        }
        [$clean, $errors] = self::validate($typed);
        foreach ($errors as $k => $msg) self::form_error($k, $msg); // the invalid field keeps its stored value
        $out = array_merge($out, $clean);

        $out['require_key']  = !empty($in['require_key']);
        $out['expose_pages'] = !empty($in['expose_pages']);

        $text = static fn(string $k): ?string => isset($in[$k]) && is_scalar($in[$k]) ? (string) $in[$k] : null;
        if (($v = $text('api_key')) !== null) $out['api_key'] = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $v);
        if ($out['api_key'] === '') $out['api_key'] = self::new_api_key();
        if (($v = $text('site_name')) !== null)     $out['site_name']     = sanitize_text_field($v);
        if (($v = $text('seo_title_tpl')) !== null) $out['seo_title_tpl'] = sanitize_text_field($v) ?: self::defaults()['seo_title_tpl'];
        if (($v = $text('default_desc')) !== null)  $out['default_desc']  = sanitize_textarea_field($v);
        if (($v = $text('default_og')) !== null)    $out['default_og']    = esc_url_raw(trim($v));
        if (($v = $text('twitter')) !== null)       $out['twitter']       = ltrim(sanitize_text_field($v), '@');
        if (($v = $text('panel_name')) !== null)    $out['panel_name']    = self::clip_panel_name(sanitize_text_field($v));
        if (($v = $text('panel_url')) !== null) {
            // Same rule as the revalidate webhook: empty or an absolute http(s) URL.
            [$url, $error] = self::check_webhook_url(trim(self::strip_invisible($v)));
            if ($error === null) $out['panel_url'] = $url;      // the invalid field keeps its stored value
            else self::form_error('panel_url', self::msg('panel_url'));
        }
        if (($v = $text('locale')) !== null) {
            $v = sanitize_text_field($v);
            if (self::valid_locale($v)) $out['locale'] = $v;
            else self::form_error('locale', self::msg('locale'));
        }

        $out['_schema'] = self::SCHEMA;
        self::$cache = null;
        self::bump_cache_gen();
        return $out;
    }

    /** @deprecated 1.1.0 Kept so an older admin class cannot fatal during a partial deploy. Use sanitize_form(). */
    public static function sanitize($in): array
    {
        return self::sanitize_form($in);
    }

    /** 'https://a.com:8443' (lowercase, default port dropped) or null when $url is not an http(s) URL. */
    public static function origin_of(string $url): ?string
    {
        $p = self::parse_http_url(trim($url));
        return $p === null ? null : self::build_origin($p['scheme'], strtolower($p['host']), $p['port'] ?? null);
    }

    public static function origins(): array
    {
        $list = self::get('cors_origins', []);
        $fe   = self::origin_of((string) self::get('frontend_url', ''));
        if ($fe !== null) $list[] = $fe;
        return array_values(array_unique($list));
    }

    public static function frontend_url(string $path = ''): string
    {
        $base = untrailingslashit((string) self::get('frontend_url')) ?: home_url();
        return $base . '/' . ltrim($path, '/');
    }

    public static function post_url(WP_Post $post): string
    {
        if ($post->post_type === 'page') return self::frontend_url($post->post_name);
        $bp = trim((string) self::get('blog_path'), '/');
        return self::frontend_url(($bp ? $bp . '/' : '') . $post->post_name);
    }

    public static function cache_gen(): int
    {
        return max(1, (int) get_option(self::GEN_OPTION, 1));
    }

    public static function bump_cache_gen(): void
    {
        update_option(self::GEN_OPTION, self::cache_gen() + 1, true);
    }

    /* ---------- Internals ---------- */

    /** Stored (any schema, any junk) > every known key, typed and valid. */
    private static function normalize(array $raw): array
    {
        if ((int) ($raw['_schema'] ?? 0) < self::SCHEMA) {
            foreach (self::LEGACY as $old => $new) {
                if (array_key_exists($old, $raw) && !array_key_exists($new, $raw)) $raw[$new] = $raw[$old];
            }
        }
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $out[$key] = array_key_exists($key, $raw) ? self::coerce($key, $raw[$key], $default) : $default;
        }
        return $out;
    }

    /** Lenient repair used for stored/legacy data: normalise what can be, fall back to the default otherwise. */
    private static function coerce(string $key, $v, $default)
    {
        return match ($key) {
            'frontend_url'           => self::pick(self::check_frontend_url(is_string($v) ? untrailingslashit(trim(self::strip_invisible($v))) : null), ''),
            'blog_path'              => self::pick(self::check_blog_path(is_string($v) ? '/' . trim((string) preg_replace('~/+~', '/', trim(self::strip_invisible($v))), '/') : null), $default),
            'cors_origins'           => self::lenient_origins($v),
            'cache_ttl_seconds'      => self::clamp($v, 0, self::MAX_TTL, $default),
            'posts_per_page'         => self::clamp($v, 1, self::MAX_PER_PAGE, $default),
            'revalidate_webhook_url', 'panel_url' => self::pick(self::check_webhook_url(is_string($v) ? self::strip_invisible($v) : $v), ''),
            'panel_name'             => is_scalar($v) ? self::clip_panel_name((string) $v) : $default,
            'redirect_to_frontend', 'require_key', 'expose_pages' => self::to_bool($v, $default),
            'locale'                 => is_string($v) && self::valid_locale(trim($v)) ? trim($v) : $default,
            'seo_title_tpl'          => is_scalar($v) && (string) $v !== '' ? (string) $v : $default,
            default                  => is_scalar($v) ? (string) $v : $default,
        };
    }

    /** Strict check of one contract key. @return array{0: mixed, 1: ?string} [value, error] */
    private static function check(string $key, $v): array
    {
        return match ($key) {
            'frontend_url'           => self::check_frontend_url($v),
            'blog_path'              => self::check_blog_path($v),
            'redirect_to_frontend'   => is_bool($v) ? [$v, null] : [null, self::msg('redirect_to_frontend')],
            'cors_origins'           => self::check_origins($v),
            'cache_ttl_seconds'      => self::check_int($v, 0, self::MAX_TTL, self::msg('cache_ttl_seconds')),
            'posts_per_page'         => self::check_int($v, 1, self::MAX_PER_PAGE, self::msg('posts_per_page')),
            'revalidate_webhook_url' => self::check_webhook_url($v),
        };
    }

    private static function check_frontend_url($v): array
    {
        if (!is_string($v)) return [null, self::msg('frontend_url')];
        $v = trim($v);
        if ($v === '') return ['', null];
        $p = self::parse_http_url($v);
        if ($p === null) return [null, self::msg('frontend_url')];
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || strpbrk($v, '?#') !== false) {
            return [null, self::msg('frontend_parts')];
        }
        if (str_ends_with($v, '/')) return [null, self::msg('frontend_slash')];
        if (isset($p['path']) && !preg_match('~^(?:/[^/]+)+$~', $p['path'])) return [null, self::msg('frontend_url')];
        return [$v, null];
    }

    private static function check_blog_path($v): array
    {
        if (!is_string($v)) return [null, self::msg('blog_path')];
        $v  = trim($v);
        $ok = strlen($v) <= 200
            && preg_match('~^(?:/[^\s/?#]+)+$~u', $v) === 1
            && preg_match('~[\x00-\x1f\x7f<>"\'\\\\]|/\.\.?(?:/|$)~', $v) === 0 // no control chars, quotes or dot-segments
            && preg_match('/\p{Cf}/u', $v) === 0;                               // no invisible bidi / zero-width marks
        return $ok ? [$v, null] : [null, self::msg('blog_path')];
    }

    private static function check_origins($v): array
    {
        if (!is_array($v) || !self::is_list($v)) return [null, self::msg('cors_origins')];
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item)) return [null, self::msg('cors_origins')];
            $item = trim($item);
            if ($item === '*') return [null, self::msg('cors_star')];
            $origin = self::normalize_origin($item);
            if ($origin === null) return [null, sprintf(self::msg('cors_item'), mb_substr($item, 0, 80))];
            $out[$origin] = true;
        }
        if (count($out) > self::MAX_ORIGINS) return [null, self::msg('cors_max')];
        return [array_keys($out), null];
    }

    private static function check_int($v, int $min, int $max, string $msg): array
    {
        return is_int($v) && $v >= $min && $v <= $max ? [$v, null] : [null, $msg];
    }

    private static function check_webhook_url($v): array
    {
        if (!is_string($v)) return [null, self::msg('revalidate_webhook_url')];
        $v = trim($v);
        if ($v === '') return ['', null];
        return self::parse_http_url($v) !== null ? [$v, null] : [null, self::msg('revalidate_webhook_url')];
    }

    /** Exact 'scheme://host[:port]' (no path, not even '/') > canonical lowercase origin, else null. */
    private static function normalize_origin(string $o): ?string
    {
        $o = strtolower(trim($o));
        if ($o === '' || preg_match('~^[\x21-\x7e]+$~', $o) !== 1) return null; // browsers send ASCII (punycode) origins
        $p = self::parse_http_url($o);
        if ($p === null || isset($p['path']) || isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) return null;
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        if ($p['scheme'] . '://' . $p['host'] . $port !== $o) return null; // rejects leftovers such as 'https://a.com:' or 'https://a.com?'
        return self::build_origin($p['scheme'], $p['host'], $p['port'] ?? null);
    }

    /** Legacy/stored origins: newline/comma string or array; strip one trailing '/', keep only valid ones. */
    private static function lenient_origins($v): array
    {
        $items = is_string($v) ? (preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: []) : (is_array($v) ? $v : []);
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item)) continue;
            $item = trim(self::strip_invisible($item));
            if (str_ends_with($item, '/')) $item = substr($item, 0, -1);
            $origin = self::normalize_origin($item);
            if ($origin !== null) $out[$origin] = true;
            if (count($out) >= self::MAX_ORIGINS) break;
        }
        return array_keys($out);
    }

    /** Browsers omit default ports in the Origin header, so the stored form must too. */
    private static function build_origin(string $scheme, string $host, ?int $port): string
    {
        $default = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port && $port !== $default ? ':' . $port : '');
    }

    /** Absolute http(s) URL with a valid host; no whitespace, control chars or backslashes. Scheme lowercased. */
    private static function parse_http_url(string $url): ?array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('~^https?://[^\s\x00-\x1f\x7f\\\\\p{Cf}]+$~iu', $url) !== 1) return null;
        $p = wp_parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || !self::valid_host($p['host'])) return null;
        if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) return null;
        $p['scheme'] = strtolower($p['scheme']);
        return $p;
    }

    private static function valid_host(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) return false;
        if ($host[0] === '[') {
            return str_ends_with($host, ']') && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?)*$/u', $host) === 1;
    }

    /** Unicode format chars (U+200B-200F, U+202A-202E, U+2066-2069, U+FEFF, ...): invisible, never meaningful in a URL or path. */
    private static function strip_invisible(string $v): string
    {
        return preg_replace('/\p{Cf}+/u', '', $v) ?? $v;
    }

    /** A panel label, not content: trimmed and bounded so it cannot bloat the autoloaded option. */
    private static function clip_panel_name(string $v): string
    {
        return trim(mb_substr(trim($v), 0, self::MAX_PANEL_NAME, 'UTF-8'));
    }

    private static function valid_locale(string $v): bool
    {
        return preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $v) === 1;
    }

    private static function pick(array $checked, $default)
    {
        return $checked[1] === null ? $checked[0] : $default;
    }

    private static function clamp($v, int $min, int $max, int $default): int
    {
        if (!is_numeric($v)) return $default;
        return (int) max($min, min($max, (float) $v));
    }

    private static function to_bool($v, bool $default): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v != 0;
        if (is_string($v)) return filter_var(trim($v), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        return $default;
    }

    private static function is_list(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    private static function form_error(string $key, string $msg): void
    {
        // settings_errors() prints messages unescaped and ours may echo user input.
        if (function_exists('add_settings_error')) add_settings_error(self::OPTION, 'rb_' . $key, esc_html($msg), 'error');
    }

    private static function new_api_key(): string
    {
        return wp_generate_password(32, false);
    }

    private static function ensure_secret(): void
    {
        if (!get_option('rb_secret')) update_option('rb_secret', wp_generate_password(40, false));
    }
}
