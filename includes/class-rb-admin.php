<?php
if (!defined('ABSPATH')) exit;

final class RB_Admin
{
    const SLUG = 'react-bridge';
    /** A frontend that does not answer within this many seconds is reported as unreachable, not as an error. */
    const PROBE_TIMEOUT = 4;

    /**
     * Every user-facing string the admin script needs, in English source text.
     * The script reads them from RB.i18n with an inline English fallback, so a missing
     * key degrades to English instead of breaking the screen.
     *
     * Placeholders are replaced in JavaScript: %s, or %1$s / %2$s when the order matters.
     *
     * @return array<string, string|array<string, string>>
     */
    public static function i18n(): array
    {
        return [
            'copied'           => __('Copied', 'react-bridge'),
            'copyFailed'       => __('Not copied', 'react-bridge'),
            'show'             => __('Show', 'react-bridge'),
            'hide'             => __('Hide', 'react-bridge'),
            /* translators: %s: plugin version reported by the API. */
            'apiActive'        => __('API active, version %s', 'react-bridge'),
            /* translators: %s: HTTP status code or a short error. */
            'apiDown'          => __('API not responding (%s)', 'react-bridge'),
            'networkError'     => __('Network error', 'react-bridge'),
            'checking'         => __('Checking', 'react-bridge'),
            'unsavedChanges'   => __('You have unsaved changes.', 'react-bridge'),
            'allSaved'         => __('All changes are saved.', 'react-bridge'),
            'saving'           => __('Saving', 'react-bridge'),
            'flushing'         => __('Clearing', 'react-bridge'),
            'cacheCleared'     => __('Cache cleared', 'react-bridge'),
            'flushFailed'      => __('Not cleared, try again', 'react-bridge'),
            'flushCache'       => __('Clear cache', 'react-bridge'),
            'generating'       => __('Generating', 'react-bridge'),
            'keyCreated'       => __('New key created.', 'react-bridge'),
            'keyFailed'        => __('Could not create a new key.', 'react-bridge'),
            'confirmRegen'     => __('The current key stops working and the frontend needs the new one. Continue?', 'react-bridge'),
            'confirmDiscard'   => __('Unsaved changes on this page will be lost. Continue?', 'react-bridge'),
            'running'          => __('Running', 'react-bridge'),
            'run'              => __('Run', 'react-bridge'),
            'noBody'           => __('The response has no body.', 'react-bridge'),
            'emptyTester'      => __('Empty', 'react-bridge'),
            'mustHttp'         => __('The address must start with http:// or https://.', 'react-bridge'),
            'noSpaces'         => __('The address must not contain spaces.', 'react-bridge'),
            'invalidUrl'       => __('This address is not valid.', 'react-bridge'),
            'noUserinfo'       => __('The address must not contain a user name or password.', 'react-bridge'),
            'noQueryHash'      => __('The frontend URL must not contain ? or #.', 'react-bridge'),
            'noTrailingSlash'  => __('The frontend URL must not end with a slash.', 'react-bridge'),
            'isWordpressApi'   => __('This is the WordPress API address. Enter the address of your frontend, for example https://example.com', 'react-bridge'),
            'badPath'          => __('The path must look like /blog: it starts with a slash and has no trailing slash, space, ? or #.', 'react-bridge'),
            'starNotAllowed'   => __('* is not allowed', 'react-bridge'),
            'onlyHttp'         => __('http or https only', 'react-bridge'),
            'noPathInOrigin'   => __('A path or trailing slash is not allowed', 'react-bridge'),
            'badOrigin'        => __('Invalid format', 'react-bridge'),
            'duplicate'        => __('Duplicate', 'react-bridge'),
            'tooManyOrigins'   => __('At most 50 origins are allowed', 'react-bridge'),
            'autoFromFrontend' => __('Automatic from the frontend URL', 'react-bridge'),
            'auto'             => __('Automatic', 'react-bridge'),
            /* translators: %s: an origin such as https://example.com. */
            'originAuto'       => __('Origin %s is allowed automatically', 'react-bridge'),
            'sampleTitle'      => __('Sample post title', 'react-bridge'),
            'sampleDesc'       => __('The default description appears here.', 'react-bridge'),
            'siteName'         => __('Site name', 'react-bridge'),
            /* translators: 1: current count, 2: maximum count. */
            'ofMax'            => __('%1$s of %2$s', 'react-bridge'),
            /* translators: %s: number of settings that will change. */
            'changesCount'     => __('%s settings will change:', 'react-bridge'),
            'nothingToChange'  => __('All values match the current settings. Nothing to change.', 'react-bridge'),
            /* translators: %s: comma separated list of field names. */
            'ignored'          => __('Ignored: %s', 'react-bridge'),
            'invalidJson'      => __('This text is not valid JSON. Paste the full text copied from your panel.', 'react-bridge'),
            'notObject'        => __('The text must be a JSON object.', 'react-bridge'),
            /* translators: %s: HTTP status code. */
            'applyFailed'      => __('Could not apply settings (code %s).', 'react-bridge'),
            'connectionFailed' => __('Could not connect. Try again.', 'react-bridge'),
            'applying'         => __('Applying', 'react-bridge'),
            'apply'            => __('Apply settings', 'react-bridge'),
            /* translators: %s: number of imported settings. */
            'importedCount'    => __('%s settings imported and saved.', 'react-bridge'),
            'clipboardBlocked' => __('The browser blocked automatic copying. The text is selected, press Ctrl+C.', 'react-bridge'),
            'unsavedNotInCopy' => __('Unsaved changes on this page are not in the copied text. Save first, then copy again.', 'react-bridge'),
            'pasteHere'        => __('Paste the copied text into the box above with Ctrl+V.', 'react-bridge'),
            /* translators: %s: an origin such as https://example.com. */
            'obOriginAllowed'  => __('Origin %s allowed', 'react-bridge'),
            /* translators: %s: the blog path, for example /blog. */
            'obPathSet'        => __('Blog path %s set', 'react-bridge'),
            /* translators: %s: HTTP status code. */
            'obReachable'      => __('Site reachable (%s)', 'react-bridge'),
            'obUnreachable'    => __('Site did not respond', 'react-bridge'),
            'obApiOk'          => __('API active', 'react-bridge'),
            'obManifestOk'     => __('Settings match', 'react-bridge'),
            'obManifestBad'    => __('Frontend URL differs in manifest', 'react-bridge'),
            /* translators: %s: number of posts. */
            'obPosts'          => __('%s posts', 'react-bridge'),
            'obSitemapOk'      => __('Sitemap ready', 'react-bridge'),
            'obFailed'         => __('Failed', 'react-bridge'),
            /* translators: separator between list items, including the trailing space. */
            'listSep'          => __(', ', 'react-bridge'),
            'waiting'          => __('Waiting', 'react-bridge'),
            /* translators: separates an old value from the new one in the settings diff. */
            'arrowTo'          => __('to', 'react-bridge'),
            'setupDone'        => __('Setup complete.', 'react-bridge'),
            'enterFrontend'    => __('Enter your frontend address.', 'react-bridge'),
            'empty'            => __('empty', 'react-bridge'),
            'on'               => __('on', 'react-bridge'),
            'off'              => __('off', 'react-bridge'),
            /* translators: short unit for bytes. */
            'unitBytes'        => __('B', 'react-bridge'),
            /* translators: short unit for kilobytes. */
            'unitKb'           => __('KB', 'react-bridge'),
            /* translators: short unit for milliseconds. */
            'unitMs'           => __('ms', 'react-bridge'),
            'labels'           => [
                'frontend_url'           => __('Frontend URL', 'react-bridge'),
                'blog_path'              => __('Blog path', 'react-bridge'),
                'redirect_to_frontend'   => __('301 redirect to the frontend', 'react-bridge'),
                'cors_origins'           => __('Allowed origins (CORS)', 'react-bridge'),
                'cache_ttl_seconds'      => __('Cache lifetime (seconds)', 'react-bridge'),
                'posts_per_page'         => __('Posts per page', 'react-bridge'),
                'revalidate_webhook_url' => __('Revalidation webhook URL', 'react-bridge'),
                'panel_name'             => __('Panel name', 'react-bridge'),
                'panel_url'              => __('Panel URL', 'react-bridge'),
            ],
        ];
    }

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('add_meta_boxes', [self::class, 'metabox']);
        add_action('save_post', [self::class, 'save_meta'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(RB_FILE), fn($l) => array_merge(['<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Settings', 'react-bridge') . '</a>'], $l));
        add_action('admin_notices', [self::class, 'notices']);
        add_action('wp_ajax_rb_regen_key', [self::class, 'regen_key']);
        add_action('wp_ajax_rb_flush', [self::class, 'flush']);
        add_action('wp_ajax_rb_onboarding', [self::class, 'onboarding']);
        add_action('wp_ajax_rb_probe_frontend', [self::class, 'probe_frontend']);
    }

    public static function menu(): void
    {
        add_menu_page('React Bridge', 'React Bridge', 'manage_options', self::SLUG, [self::class, 'page'], 'dashicons-rest-api', 58);
    }

    public static function register(): void
    {
        register_setting('rb_group', RB_Settings::OPTION, ['type' => 'array', 'show_in_rest' => false, 'sanitize_callback' => [RB_Settings::class, 'sanitize_form']]);
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) return;
        // File mtime in the version so a redeployed asset is never served from a stale browser cache.
        $ver = static function (string $file): string {
            $path = RB_DIR . $file;
            return RB_VERSION . (is_file($path) ? '.' . filemtime($path) : '');
        };
        wp_enqueue_style('rb-admin', RB_URL . 'assets/admin.css', [], $ver('assets/admin.css'));
        wp_enqueue_script('rb-admin', RB_URL . 'assets/admin.js', [], $ver('assets/admin.js'), true);
        wp_localize_script('rb-admin', 'RB', [
            'base'    => rest_url(RB_NS),
            'key'     => RB_Settings::get('api_key'),
            'require' => (bool) RB_Settings::get('require_key'),
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rb_admin'),
            'home'    => untrailingslashit(home_url()),
            'version' => RB_VERSION,
            // Paste-from-panel applies settings through the validated REST contract (cookie auth + nonce).
            'settingsUrl' => rest_url(RB_NS . '/blog-settings'),
            'restNonce'   => wp_create_nonce('wp_rest'),
            // Quick-start wizard: the server decides whether it is still needed.
            'setup'      => RB_Settings::setup_state(),
            // Optional external admin panel; empty means "no panel configured", and the links stay hidden.
            'panelUrl'   => (string) RB_Settings::get('panel_url', ''),
            'panelName'  => (string) RB_Settings::get('panel_name', ''),
            'siteOrigin' => RB_Settings::origin_of(home_url()),
            'motion'     => true,
            'rtl'        => is_rtl(),
            'i18n'       => self::i18n(),
        ]);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) return;
        $s     = RB_Settings::all();
        $setup = RB_Settings::setup_state();
        require RB_DIR . 'views/settings-page.php';
    }

    public static function notices(): void
    {
        $screen = get_current_screen();
        if (!RB_Settings::get('frontend_url') && $screen && $screen->id !== 'toplevel_page_' . self::SLUG && current_user_can('manage_options')) {
            echo '<div class="notice notice-warning"><p><strong>React Bridge:</strong> ' . esc_html__('The frontend URL is not set yet.', 'react-bridge')
                . ' <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Set it up', 'react-bridge') . '</a></p></div>';
        }
    }

    public static function flush(): void
    {
        check_ajax_referer('rb_admin');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('You do not have permission to do this.', 'react-bridge')], 403);
        RB_Api::flush_cache();
        wp_send_json_success();
    }

    public static function regen_key(): void
    {
        check_ajax_referer('rb_admin');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('You do not have permission to do this.', 'react-bridge')], 403);
        // The form sanitizer expects posted form strings; this is a single trusted field write.
        remove_filter('sanitize_option_' . RB_Settings::OPTION, [RB_Settings::class, 'sanitize_form']);
        $s = (array) get_option(RB_Settings::OPTION, []);
        $s['api_key'] = wp_generate_password(32, false);
        update_option(RB_Settings::OPTION, $s);
        wp_send_json_success(['key' => $s['api_key']]);
    }

    /* ---------- Quick-start wizard ---------- */

    /** Marks the wizard as finished (done=1) or brings it back (done=0). */
    public static function onboarding(): void
    {
        check_ajax_referer('rb_admin');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('You do not have permission to do this.', 'react-bridge')], 403);
        $done = isset($_POST['done']) && (string) sanitize_text_field(wp_unslash($_POST['done'])) === '1';
        RB_Settings::set_onboarding_done($done);
        wp_send_json_success(['done' => $done, 'setup' => RB_Settings::setup_state()]);
    }

    /** Best-effort reachability check of the frontend the admin just entered; never blocks the wizard. */
    public static function probe_frontend(): void
    {
        check_ajax_referer('rb_admin');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('You do not have permission to do this.', 'react-bridge')], 403);

        $url = isset($_POST['url']) ? trim((string) wp_unslash($_POST['url'])) : '';
        [, $errors] = RB_Settings::validate(['frontend_url' => $url]);
        if (isset($errors['frontend_url']) || $url === '') {
            wp_send_json_error(['message' => $errors['frontend_url'] ?? __('The frontend URL is required.', 'react-bridge')], 400);
        }

        wp_send_json_success(self::probe_url($url));
    }

    /**
     * HEAD the frontend, falling back to GET when the host refuses HEAD (403/405).
     * Only http(s) is ever requested, so a redirect to another scheme cannot be followed.
     *
     * @return array{reachable: bool, status: ?int, error: ?string}
     */
    public static function probe_url(string $url): array
    {
        $scheme = strtolower((string) (wp_parse_url($url, PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['reachable' => false, 'status' => null, 'error' => __('The address must be http or https.', 'react-bridge')];
        }

        // reject_unsafe_urls is deliberately off: a frontend on localhost or a LAN address is a normal
        // development setup, and the URL comes from an administrator, not from a request payload.
        $args = [
            'timeout'     => self::PROBE_TIMEOUT,
            'redirection' => 3,
            'user-agent'  => 'React Bridge/' . RB_VERSION,
        ];

        $res = wp_remote_head($url, $args);
        if (!is_wp_error($res) && in_array((int) wp_remote_retrieve_response_code($res), [403, 405], true)) {
            $res = wp_remote_get($url, $args + ['limit_response_size' => 2048]);
        }

        if (is_wp_error($res)) {
            // The URL is an admin-entered site address, not a secret; the message never carries credentials.
            error_log('[react-bridge] frontend probe failed for ' . self::safe_url($url) . ': ' . $res->get_error_message());
            return ['reachable' => false, 'status' => null, 'error' => $res->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        return ['reachable' => $status >= 200 && $status < 400, 'status' => $status ?: null, 'error' => null];
    }

    /** scheme://host[:port][/path] only: query strings and userinfo never reach the log. */
    private static function safe_url(string $url): string
    {
        $p = wp_parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) return '(invalid url)';
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    }

    /* ---------- Per-post SEO metabox ---------- */
    public static function metabox(): void
    {
        add_meta_box('rb_seo', __('SEO (React Bridge)', 'react-bridge'), [self::class, 'metabox_html'], ['post', 'page'], 'normal', 'high');
    }

    public static function metabox_html(WP_Post $post): void
    {
        wp_nonce_field('rb_meta', 'rb_meta_nonce');
        $v = fn($k) => esc_attr(get_post_meta($post->ID, $k, true));
        $preview = $post->post_status === 'publish' ? RB_Settings::post_url($post) : '';
        ?>
        <style>.rb-mb{display:grid;gap:12px}.rb-mb label{display:block;font-weight:600;margin-bottom:4px}.rb-mb input[type=text],.rb-mb input[type=url],.rb-mb textarea{width:100%}.rb-mb small{color:#666}.rb-mb .rb-count{float:<?= is_rtl() ? 'left' : 'right' ?>;font-weight:400}</style>
        <div class="rb-mb">
            <?php if ($preview): ?><p><small><?php esc_html_e('Address on the frontend:', 'react-bridge') ?> <a href="<?= esc_url($preview) ?>" target="_blank" dir="ltr"><?= esc_html($preview) ?></a></small></p><?php endif; ?>
            <div><label><?php esc_html_e('SEO title', 'react-bridge') ?> <small class="rb-count" data-for="_rb_seo_title" data-max="60"></small></label>
                <input type="text" name="_rb_seo_title" value="<?= $v('_rb_seo_title') ?>" placeholder="<?php esc_attr_e('Empty = the post title with the template from the settings', 'react-bridge') ?>"></div>
            <div><label><?php esc_html_e('Meta description', 'react-bridge') ?> <small class="rb-count" data-for="_rb_seo_desc" data-max="160"></small></label>
                <textarea name="_rb_seo_desc" rows="3" placeholder="<?php esc_attr_e('Empty = the post excerpt', 'react-bridge') ?>"><?= esc_textarea(get_post_meta($post->ID, '_rb_seo_desc', true)) ?></textarea></div>
            <div><label><?php esc_html_e('Sharing image (OG)', 'react-bridge') ?></label>
                <input type="url" name="_rb_og_image" value="<?= $v('_rb_og_image') ?>" placeholder="<?php esc_attr_e('Empty = the featured image', 'react-bridge') ?>" dir="ltr"></div>
            <div><label><input type="checkbox" name="_rb_noindex" value="1" <?php checked(get_post_meta($post->ID, '_rb_noindex', true), '1') ?>> <?php esc_html_e('Keep this out of search results (noindex)', 'react-bridge') ?></label></div>
        </div>
        <script>document.querySelectorAll('.rb-count').forEach(c=>{const f=document.querySelector('[name="'+c.dataset.for+'"]');const u=()=>{c.textContent=f.value.length+'/'+c.dataset.max;c.style.color=f.value.length>c.dataset.max?'#c00':'#666'};f.addEventListener('input',u);u()});</script>
        <?php
    }

    public static function save_meta(int $id, WP_Post $post): void
    {
        if (!isset($_POST['rb_meta_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['rb_meta_nonce'])), 'rb_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $id)) return;
        foreach (['_rb_seo_title' => 'sanitize_text_field', '_rb_seo_desc' => 'sanitize_textarea_field', '_rb_og_image' => 'esc_url_raw'] as $k => $fn) {
            $val = $fn(wp_unslash($_POST[$k] ?? ''));
            $val === '' ? delete_post_meta($id, $k) : update_post_meta($id, $k, $val);
        }
        empty($_POST['_rb_noindex']) ? delete_post_meta($id, '_rb_noindex') : update_post_meta($id, '_rb_noindex', '1');
    }
}
