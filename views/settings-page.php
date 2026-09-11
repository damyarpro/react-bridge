<?php
if (!defined('ABSPATH')) exit;
/** @var array $s Typed settings from RB_Settings::all(). */
/** @var array $setup Onboarding state from RB_Settings::setup_state(); missing means done. */
$base   = rest_url(RB_NS);
$fe     = $s['frontend_url'] ?: 'https://example.com';
$bp     = $s['blog_path'];
$secret = (string) get_option('rb_secret');
$fa     = static fn($v): string => strtr((string) $v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
/** Persian digits only on an RTL Persian admin; every other locale keeps ASCII digits. */
$num    = (is_rtl() && str_starts_with(get_locale(), 'fa')) ? $fa : static fn($v): string => (string) $v;
$code   = static fn(string $file, string $src, bool $fill = false): string =>
    '<div class="rb-code"><div class="rb-code-bar"><span class="rb-file" dir="ltr">' . esc_html($file) . '</span>'
    . '<button type="button" class="rb-copy">' . esc_html__('Copy', 'react-bridge') . '</button></div>'
    . '<pre tabindex="0"' . ($fill ? ' data-fill' : '') . '>' . esc_html($src) . '</pre></div>';

// Field-level errors from RB_Settings::sanitize_form() (codes "rb_<key>"); reading them also loads the post-redirect transient.
$errors = [];
foreach (get_settings_errors('rb_settings') as $e) {
    if (str_starts_with((string) $e['code'], 'rb_')) $errors[substr((string) $e['code'], 3)] = html_entity_decode((string) $e['message'], ENT_QUOTES, 'UTF-8');
}
/** aria-describedby = help text (+ server error), aria-invalid when the server rejected the field. */
$inv = static function (string $k, string $help = '') use ($errors): string {
    $ids = trim($help . (isset($errors[$k]) ? ' rb-err-' . $k : ''));
    return ($ids !== '' ? ' aria-describedby="' . esc_attr($ids) . '"' : '') . (isset($errors[$k]) ? ' aria-invalid="true"' : '');
};
$err = static fn(string $k): string => isset($errors[$k])
    ? '<p class="rb-err" id="rb-err-' . esc_attr($k) . '" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html($errors[$k]) . '</p>'
    : '';
$fe_origin   = $s['frontend_url'] ? RB_Settings::origin_of((string) $s['frontend_url']) : null;
$ttl_presets = [
    0     => __('No cache', 'react-bridge'),
    60    => __('1 minute', 'react-bridge'),
    300   => __('5 minutes', 'react-bridge'),
    3600  => __('1 hour', 'react-bridge'),
    86400 => __('1 day', 'react-bridge'),
];
$setup_done  = !isset($setup) || !is_array($setup) || !empty($setup['done']);
/** Optional external admin panel; both keys may be missing on an old stored option. */
$panel_name  = trim((string) ($s['panel_name'] ?? ''));
$panel_url   = trim((string) ($s['panel_url'] ?? ''));
$panel_label = $panel_name !== '' ? $panel_name : __('panel', 'react-bridge');
$err_keys    = array_keys($errors);
$open_group  = static fn(array $keys) => (bool) array_intersect($keys, $err_keys);
$tabs = [
    'connect'  => [__('Connection', 'react-bridge'), 'dashicons-admin-links'],
    'advanced' => [__('Advanced', 'react-bridge'), 'dashicons-admin-generic'],
    'status'   => [__('Status', 'react-bridge'), 'dashicons-performance'],
    'docs'     => [__('Guide', 'react-bridge'), 'dashicons-book-alt'],
];
$endpoints = [
    [['GET'], '/manifest', __('Settings, categories, menus and counters', 'react-bridge'), '/manifest'],
    [['GET'], '/posts', __('Paginated list with filters and search', 'react-bridge'), '/posts'],
    [['GET'], '/posts/{slug}', __('Full post with SEO', 'react-bridge'), null],
    [['GET'], '/pages/{slug}', __('WordPress page', 'react-bridge'), null],
    [['GET'], '/taxonomies', __('Categories and tags', 'react-bridge'), '/taxonomies'],
    [['GET'], '/render?path=', __('Full HTML for crawlers', 'react-bridge'), '/render?path=' . $bp],
    [['GET'], '/sitemap.xml', __('Sitemap with frontend URLs', 'react-bridge'), '/sitemap.xml'],
    [['GET'], '/health', __('Plugin status and version', 'react-bridge'), '/health'],
    [['GET', 'PATCH'], '/blog-settings', __('Blog settings for your admin panel', 'react-bridge'), false],
];
$n = esc_attr(RB_Settings::OPTION);
$export = RB_Settings::panel_export();
?>
<div class="rb" id="rb-app" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>" data-tab="connect" data-setup="<?= $setup_done ? 'done' : 'pending' ?>" data-motion="on">
  <header class="rb-header">
    <h1>React Bridge</h1>
    <div class="rb-status" id="rb-conn" role="status" aria-live="polite" data-state="checking">
      <span class="dashicons dashicons-update" aria-hidden="true"></span>
      <span class="rb-status-text"><?= esc_html__('Checking', 'react-bridge') ?></span>
    </div>
  </header>

  <section class="rb-onboarding" id="rb-onboarding" data-step="1" aria-label="<?= esc_attr__('Quick setup', 'react-bridge') ?>"<?= $setup_done ? ' hidden' : '' ?>>
    <ol class="rb-steps">
      <li class="rb-step" data-step="1" aria-current="step"><span class="rb-dot" aria-hidden="true"></span><span class="rb-step-label"><?= esc_html__('Frontend URL', 'react-bridge') ?></span></li>
      <li class="rb-step" data-step="2"><span class="rb-dot" aria-hidden="true"></span><span class="rb-step-label"><?= esc_html__('Connect panel', 'react-bridge') ?></span></li>
      <li class="rb-step" data-step="3"><span class="rb-dot" aria-hidden="true"></span><span class="rb-step-label"><?= esc_html__('Check', 'react-bridge') ?></span></li>
    </ol>

    <div class="rb-step-panel" data-step="1">
      <h2><?= esc_html__('Enter your React site URL', 'react-bridge') ?></h2>
      <div class="rb-ob-row">
        <input type="url" dir="ltr" id="rb-ob-frontend" value="<?= esc_attr($s['frontend_url']) ?>" placeholder="https://example.com" autocomplete="off" spellcheck="false" aria-label="<?= esc_attr__('Frontend URL', 'react-bridge') ?>">
        <button type="button" class="rb-btn rb-btn-primary" id="rb-ob-next-1"><span class="rb-btn-label"><?= esc_html__('Continue', 'react-bridge') ?></span></button>
      </div>
      <p class="rb-hint" id="rb-ob-hint-1" role="alert" hidden></p>
      <button type="button" class="rb-link-btn" id="rb-ob-paste"><?= esc_html__('Paste settings from your panel instead', 'react-bridge') ?></button>
      <ul class="rb-auto" id="rb-ob-auto" aria-live="polite"></ul>
    </div>

    <div class="rb-step-panel" data-step="2">
      <h2><?= esc_html__('Send settings to your panel', 'react-bridge') ?></h2>
      <div class="rb-ob-row">
        <button type="button" class="rb-btn rb-btn-primary" id="rb-ob-copy"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Copy settings', 'react-bridge') ?></span></button>
        <a class="rb-btn rb-btn-ghost" id="rb-ob-panel" href="<?= esc_url($panel_url ?: '#') ?>" target="_blank" rel="noopener"<?= $panel_url ? '' : ' hidden' ?>><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html(sprintf(__('Open %s', 'react-bridge'), $panel_label)) ?></span></a>
      </div>
      <p class="rb-ob-note"><?= esc_html__('In your panel, click "Paste from plugin".', 'react-bridge') ?></p>
      <div class="rb-ob-row">
        <button type="button" class="rb-link-btn" id="rb-ob-back-2"><?= esc_html__('Back', 'react-bridge') ?></button>
        <button type="button" class="rb-btn rb-btn-primary" id="rb-ob-next-2"><span class="rb-btn-label"><?= esc_html__('Continue', 'react-bridge') ?></span></button>
      </div>
    </div>

    <div class="rb-step-panel" data-step="3">
      <h2><?= esc_html__('Check the connection', 'react-bridge') ?></h2>
      <ul class="rb-checks" id="rb-ob-checks" aria-live="polite">
        <li data-check="health" data-state="idle"><span class="dashicons dashicons-marker" aria-hidden="true"></span><span class="rb-check-text">API</span></li>
        <li data-check="manifest" data-state="idle"><span class="dashicons dashicons-marker" aria-hidden="true"></span><span class="rb-check-text"><?= esc_html__('Manifest', 'react-bridge') ?></span></li>
        <li data-check="posts" data-state="idle"><span class="dashicons dashicons-marker" aria-hidden="true"></span><span class="rb-check-text"><?= esc_html__('Posts', 'react-bridge') ?></span></li>
        <li data-check="sitemap" data-state="idle"><span class="dashicons dashicons-marker" aria-hidden="true"></span><span class="rb-check-text"><?= esc_html__('Sitemap', 'react-bridge') ?></span></li>
      </ul>
      <div class="rb-ob-row">
        <button type="button" class="rb-link-btn" id="rb-ob-back-3"><?= esc_html__('Back', 'react-bridge') ?></button>
        <button type="button" class="rb-btn rb-btn-ghost" id="rb-ob-rerun"><span class="rb-btn-label"><?= esc_html__('Again', 'react-bridge') ?></span></button>
        <button type="button" class="rb-btn rb-btn-primary" id="rb-ob-done"><span class="rb-btn-label"><?= esc_html__('Done', 'react-bridge') ?></span></button>
      </div>
    </div>
  </section>

  <div class="rb-notices">
    <?php settings_errors('rb_settings'); ?>
    <?php if (!empty($_GET['settings-updated']) && !$errors): ?>
      <div class="notice notice-success is-dismissible"><p><?= esc_html__('Settings saved.', 'react-bridge') ?></p></div>
    <?php endif; ?>
  </div>

  <nav class="rb-nav" aria-label="<?= esc_attr__('React Bridge sections', 'react-bridge') ?>">
    <div class="rb-tabs" role="tablist">
      <?php foreach ($tabs as $id => [$label, $icon]): ?>
        <button type="button" role="tab" class="rb-tab" id="rb-tab-<?= esc_attr($id) ?>" aria-controls="rb-<?= esc_attr($id) ?>" aria-selected="false" tabindex="-1" data-tab="<?= esc_attr($id) ?>">
          <span class="dashicons <?= esc_attr($icon) ?>" aria-hidden="true"></span>
          <span class="rb-tab-label"><?= esc_html($label) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </nav>

  <div class="rb-main">
    <form method="post" action="options.php" id="rb-form" novalidate>
      <?php settings_fields('rb_group'); ?>

      <!-- ================= Connection ================= -->
      <section id="rb-connect" class="rb-panel" role="tabpanel" aria-labelledby="rb-tab-connect" tabindex="0" hidden>
        <section class="rb-card" id="rb-card-frontend" style="--i:0" aria-labelledby="rb-h-frontend">
          <header class="rb-card-head"><h2 id="rb-h-frontend"><?= esc_html__('Frontend', 'react-bridge') ?></h2></header>
          <div class="rb-card-body">
            <div class="rb-field">
              <label for="rb-frontend-url"><?= esc_html__('Frontend URL', 'react-bridge') ?></label>
              <input type="url" dir="ltr" id="rb-frontend-url" name="<?= $n ?>[frontend_url]" value="<?= esc_attr($s['frontend_url']) ?>" placeholder="https://example.com" autocomplete="off" spellcheck="false" title="<?= esc_attr__('URL of your React site, with http or https and no trailing slash', 'react-bridge') ?>"<?= $inv('frontend_url') ?>>
              <p class="rb-hint" data-hint-for="rb-frontend-url" role="alert" hidden></p>
              <?= $err('frontend_url') ?>
            </div>

            <div class="rb-field">
              <label for="rb-blog-path"><?= esc_html__('Blog path', 'react-bridge') ?></label>
              <input type="text" dir="ltr" id="rb-blog-path" name="<?= $n ?>[blog_path]" value="<?= esc_attr($bp) ?>" placeholder="/blog" autocomplete="off" spellcheck="false" title="<?= esc_attr__('Must start with a slash, no trailing slash and no spaces', 'react-bridge') ?>"<?= $inv('blog_path') ?>>
              <p class="rb-preview"><code dir="ltr" id="rb-path-preview"><?= esc_html($fe . $bp) ?>/hello</code></p>
              <p class="rb-hint" data-hint-for="rb-blog-path" role="alert" hidden></p>
              <?= $err('blog_path') ?>
            </div>

            <p class="rb-autoline" id="rb-autoline"></p>
          </div>
        </section>

        <section class="rb-card" id="rb-card-transfer" style="--i:1" aria-labelledby="rb-h-transfer">
          <header class="rb-card-head"><h2 id="rb-h-transfer"><?= $panel_name !== ''
            ? esc_html(sprintf(__('Transfer to %s', 'react-bridge'), $panel_name))
            : esc_html__('Transfer to panel', 'react-bridge') ?></h2></header>
          <div class="rb-card-body">
            <div class="rb-transfer-actions">
              <button type="button" class="rb-btn rb-btn-primary" id="rb-export-copy"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html(sprintf(__('Copy for %s', 'react-bridge'), $panel_label)) ?></span></button>
              <button type="button" class="rb-btn rb-btn-ghost" id="rb-import-open" aria-expanded="false" aria-controls="rb-import"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html(sprintf(__('Paste from %s', 'react-bridge'), $panel_label)) ?></span></button>
              <a class="rb-btn rb-btn-ghost" id="rb-open-panel" href="<?= esc_url($panel_url ?: '#') ?>" target="_blank" rel="noopener"<?= $panel_url ? '' : ' hidden' ?>><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html(sprintf(__('Open %s', 'react-bridge'), $panel_label)) ?></span></a>
            </div>
            <p class="rb-hint" id="rb-transfer-note" role="alert" hidden></p>
            <script type="application/json" id="rb-export-data"><?= wp_json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

            <div class="rb-import" id="rb-import" hidden>
              <div class="rb-field">
                <label for="rb-import-text"><?= esc_html__('Settings text from your panel', 'react-bridge') ?></label>
                <textarea id="rb-import-text" dir="ltr" rows="6" spellcheck="false" placeholder='{ "frontend_url": "https://example.com", "blog_path": "/blog/articles" }' title="<?= esc_attr(sprintf(__('Only the %s blog settings are applied; other keys are ignored', 'react-bridge'), $num(7))) ?>"></textarea>
              </div>
              <div class="rb-import-preview" id="rb-import-preview" aria-live="polite"></div>
              <div class="rb-transfer-actions">
                <button type="button" class="rb-btn rb-btn-primary" id="rb-import-apply" disabled><span class="rb-btn-label"><?= esc_html__('Apply settings', 'react-bridge') ?></span></button>
                <button type="button" class="rb-btn rb-btn-ghost" id="rb-import-cancel"><span class="rb-btn-label"><?= esc_html__('Cancel', 'react-bridge') ?></span></button>
              </div>
            </div>

            <p class="rb-footline"><button type="button" class="rb-link-btn" id="rb-restart-onboarding"><?= esc_html__('Run setup again', 'react-bridge') ?></button></p>
          </div>
        </section>
      </section>

      <!-- ================= Advanced ================= -->
      <section id="rb-advanced" class="rb-panel" role="tabpanel" aria-labelledby="rb-tab-advanced" tabindex="0" hidden>
        <details class="rb-group" id="rb-group-access" style="--i:0"<?= $open_group(['cors_origins', 'require_key', 'api_key']) ? ' open' : '' ?>>
          <summary><span class="dashicons dashicons-shield" aria-hidden="true"></span><span class="rb-group-title"><?= esc_html__('Access', 'react-bridge') ?></span><span class="dashicons dashicons-arrow-down-alt2 rb-chevron" aria-hidden="true"></span></summary>
          <div class="rb-group-wrap"><div class="rb-group-body">
            <div class="rb-field">
              <label for="rb-cors"><?= esc_html__('Allowed origins', 'react-bridge') ?></label>
              <textarea id="rb-cors" dir="ltr" rows="3" name="<?= $n ?>[cors_origins]" placeholder="http://localhost:5173&#10;https://panel.example.com" spellcheck="false" title="<?= esc_attr(sprintf(__('One origin per line as scheme://host[:port], no path, up to %s entries', 'react-bridge'), $num(50))) ?>" data-frontend-origin="<?= esc_attr((string) $fe_origin) ?>"<?= $inv('cors_origins') ?>><?= esc_textarea(implode("\n", (array) $s['cors_origins'])) ?></textarea>
              <ul class="rb-chips" id="rb-origin-chips" aria-label="<?= esc_attr__('Origins preview', 'react-bridge') ?>"></ul>
              <?= $err('cors_origins') ?>
            </div>

            <div class="rb-switch-row">
              <input type="checkbox" role="switch" class="rb-switch" id="rb-require-key" name="<?= $n ?>[require_key]" value="1" <?php checked($s['require_key']) ?> title="<?= esc_attr__('The key is sent in the X-RB-Key header', 'react-bridge') ?>">
              <div class="rb-switch-text"><label for="rb-require-key"><?= esc_html__('Require API key', 'react-bridge') ?></label></div>
            </div>

            <div class="rb-field">
              <label for="rb-key"><?= esc_html__('API key', 'react-bridge') ?></label>
              <div class="rb-input-group">
                <input type="text" dir="ltr" class="rb-masked" id="rb-key" name="<?= $n ?>[api_key]" value="<?= esc_attr($s['api_key']) ?>" readonly autocomplete="off" spellcheck="false" title="<?= esc_attr__('This key is visible inside the React build', 'react-bridge') ?>">
                <button type="button" class="rb-btn rb-btn-ghost" data-reveal="rb-key" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Show', 'react-bridge') ?></span></button>
                <button type="button" class="rb-btn rb-btn-ghost" data-copy-from="rb-key"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Copy', 'react-bridge') ?></span></button>
                <button type="button" class="rb-btn rb-btn-ghost" id="rb-regen"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('New key', 'react-bridge') ?></span></button>
              </div>
            </div>
          </div></div>
        </details>

        <details class="rb-group" id="rb-group-cache" style="--i:1"<?= $open_group(['cache_ttl_seconds', 'posts_per_page', 'revalidate_webhook_url', 'expose_pages', 'redirect_to_frontend']) ? ' open' : '' ?>>
          <summary><span class="dashicons dashicons-performance" aria-hidden="true"></span><span class="rb-group-title"><?= esc_html__('Cache and revalidation', 'react-bridge') ?></span><span class="dashicons dashicons-arrow-down-alt2 rb-chevron" aria-hidden="true"></span></summary>
          <div class="rb-group-wrap"><div class="rb-group-body">
            <div class="rb-field">
              <label for="rb-ttl"><?= esc_html__('Cache lifetime', 'react-bridge') ?></label>
              <div class="rb-ttl">
                <input type="number" dir="ltr" id="rb-ttl" name="<?= $n ?>[cache_ttl_seconds]" value="<?= (int) $s['cache_ttl_seconds'] ?>" min="0" max="86400" step="1" inputmode="numeric" title="<?= esc_attr(sprintf(__('From %1$s to %2$s seconds; zero means no cache', 'react-bridge'), $num(0), $num(86400))) ?>"<?= $inv('cache_ttl_seconds') ?>>
                <div class="rb-seg" role="group" aria-label="<?= esc_attr__('Preset values', 'react-bridge') ?>">
                  <?php foreach ($ttl_presets as $sec => $label): ?>
                    <button type="button" class="rb-seg-btn" data-ttl="<?= (int) $sec ?>" aria-pressed="<?= (int) $s['cache_ttl_seconds'] === $sec ? 'true' : 'false' ?>"><?= esc_html($label) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <?= $err('cache_ttl_seconds') ?>
            </div>

            <div class="rb-field rb-field-narrow">
              <label for="rb-pp"><?= esc_html__('Posts per page', 'react-bridge') ?></label>
              <input type="number" dir="ltr" id="rb-pp" name="<?= $n ?>[posts_per_page]" value="<?= (int) $s['posts_per_page'] ?>" min="1" max="50" step="1" inputmode="numeric" title="<?= esc_attr(sprintf(__('From %1$s to %2$s', 'react-bridge'), $num(1), $num(50))) ?>"<?= $inv('posts_per_page') ?>>
              <?= $err('posts_per_page') ?>
            </div>

            <div class="rb-field">
              <label for="rb-webhook"><?= esc_html__('Webhook URL', 'react-bridge') ?></label>
              <input type="url" dir="ltr" id="rb-webhook" name="<?= $n ?>[revalidate_webhook_url]" value="<?= esc_attr($s['revalidate_webhook_url']) ?>" placeholder="https://example.com/api/revalidate" autocomplete="off" spellcheck="false" title="<?= esc_attr__('Signed POST with the X-RB-Signature header after every post change', 'react-bridge') ?>"<?= $inv('revalidate_webhook_url') ?>>
              <p class="rb-hint" data-hint-for="rb-webhook" role="alert" hidden></p>
              <?= $err('revalidate_webhook_url') ?>
            </div>

            <div class="rb-field">
              <label for="rb-secret"><?= esc_html__('Webhook signing secret', 'react-bridge') ?></label>
              <div class="rb-input-group">
                <input type="text" dir="ltr" class="rb-masked" id="rb-secret" value="<?= esc_attr($secret) ?>" readonly autocomplete="off" spellcheck="false" title="<?= esc_attr__('Keep this secret on the frontend server only', 'react-bridge') ?>">
                <button type="button" class="rb-btn rb-btn-ghost" data-reveal="rb-secret" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Show', 'react-bridge') ?></span></button>
                <button type="button" class="rb-btn rb-btn-ghost" data-copy-from="rb-secret"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Copy', 'react-bridge') ?></span></button>
              </div>
            </div>

            <div class="rb-switch-row">
              <input type="checkbox" role="switch" class="rb-switch" id="rb-expose" name="<?= $n ?>[expose_pages]" value="1" <?php checked($s['expose_pages']) ?>>
              <div class="rb-switch-text"><label for="rb-expose"><?= esc_html__('Pages in the API', 'react-bridge') ?></label></div>
            </div>

            <div class="rb-switch-row">
              <input type="checkbox" role="switch" class="rb-switch" id="rb-redirect" name="<?= $n ?>[redirect_to_frontend]" value="1" <?php checked($s['redirect_to_frontend']) ?> title="<?= esc_attr__('301 redirect from WordPress pages to the frontend', 'react-bridge') ?>"<?= $inv('redirect_to_frontend') ?>>
              <div class="rb-switch-text">
                <label for="rb-redirect"><?= esc_html__('Redirect to frontend', 'react-bridge') ?></label>
                <?= $err('redirect_to_frontend') ?>
              </div>
            </div>
          </div></div>
        </details>

        <details class="rb-group" id="rb-group-seo" style="--i:2"<?= $open_group(['site_name', 'seo_title_tpl', 'default_desc', 'default_og', 'twitter', 'locale']) ? ' open' : '' ?>>
          <summary><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="rb-group-title"><?= esc_html__('SEO', 'react-bridge') ?></span><span class="dashicons dashicons-arrow-down-alt2 rb-chevron" aria-hidden="true"></span></summary>
          <div class="rb-group-wrap"><div class="rb-group-body">
            <div class="rb-grid-2">
              <div class="rb-field">
                <label for="rb-site-name"><?= esc_html__('Site name', 'react-bridge') ?></label>
                <input type="text" id="rb-site-name" name="<?= $n ?>[site_name]" value="<?= esc_attr($s['site_name']) ?>">
              </div>
              <div class="rb-field">
                <label for="rb-title-tpl"><?= esc_html__('Title template', 'react-bridge') ?> <span class="rb-counter" data-counter="title" aria-live="polite"></span></label>
                <input type="text" dir="ltr" id="rb-title-tpl" name="<?= $n ?>[seo_title_tpl]" value="<?= esc_attr($s['seo_title_tpl']) ?>" title="<?= esc_attr__('Variables: %title% and %site%', 'react-bridge') ?>">
              </div>
              <div class="rb-field rb-span-2">
                <label for="rb-desc"><?= esc_html__('Default description', 'react-bridge') ?> <span class="rb-counter" data-counter="desc" aria-live="polite"></span></label>
                <textarea id="rb-desc" rows="3" name="<?= $n ?>[default_desc]"><?= esc_textarea($s['default_desc']) ?></textarea>
              </div>
              <div class="rb-field">
                <label for="rb-og"><?= esc_html__('Default image', 'react-bridge') ?></label>
                <input type="url" dir="ltr" id="rb-og" name="<?= $n ?>[default_og]" value="<?= esc_attr($s['default_og']) ?>" placeholder="https://example.com/og.jpg" title="<?= esc_attr(sprintf(__('Recommended size %1$s by %2$s pixels', 'react-bridge'), $num(1200), $num(630))) ?>">
              </div>
              <div class="rb-field">
                <label for="rb-twitter"><?= esc_html__('X account', 'react-bridge') ?></label>
                <input type="text" dir="ltr" id="rb-twitter" name="<?= $n ?>[twitter]" value="<?= esc_attr($s['twitter']) ?>" placeholder="example">
              </div>
              <div class="rb-field">
                <label for="rb-locale"><?= esc_html__('Language', 'react-bridge') ?></label>
                <input type="text" dir="ltr" id="rb-locale" name="<?= $n ?>[locale]" value="<?= esc_attr($s['locale']) ?>" placeholder="en_US" title="<?= esc_attr__('For example en_US; affects og:locale and text direction', 'react-bridge') ?>"<?= $inv('locale') ?>>
                <?= $err('locale') ?>
              </div>
              <div class="rb-field rb-span-2">
                <div class="rb-serp" data-home="<?= esc_attr(untrailingslashit(home_url())) ?>">
                  <div class="rb-serp-url" dir="ltr" id="rb-serp-url"></div>
                  <div class="rb-serp-title" id="rb-serp-title"></div>
                  <div class="rb-serp-desc" id="rb-serp-desc"></div>
                </div>
              </div>
            </div>
          </div></div>
        </details>

        <details class="rb-group" id="rb-group-panel" style="--i:3"<?= $open_group(['panel_name', 'panel_url']) ? ' open' : '' ?>>
          <summary><span class="dashicons dashicons-admin-home" aria-hidden="true"></span><span class="rb-group-title"><?= esc_html__('Admin panel', 'react-bridge') ?></span><span class="dashicons dashicons-arrow-down-alt2 rb-chevron" aria-hidden="true"></span></summary>
          <div class="rb-group-wrap"><div class="rb-group-body">
            <div class="rb-field">
              <label for="rb-panel-name"><?= esc_html__('Panel name', 'react-bridge') ?></label>
              <input type="text" id="rb-panel-name" name="<?= $n ?>[panel_name]" value="<?= esc_attr($panel_name) ?>" maxlength="60" autocomplete="off" placeholder="<?= esc_attr__('My admin panel', 'react-bridge') ?>" title="<?= esc_attr(sprintf(__('Shown on the transfer buttons, up to %s characters', 'react-bridge'), $num(60))) ?>"<?= $inv('panel_name') ?>>
              <?= $err('panel_name') ?>
            </div>

            <div class="rb-field">
              <label for="rb-panel-url"><?= esc_html__('Panel URL', 'react-bridge') ?></label>
              <input type="url" dir="ltr" id="rb-panel-url" name="<?= $n ?>[panel_url]" value="<?= esc_attr($panel_url) ?>" placeholder="https://example.com/admin/blog-settings" autocomplete="off" spellcheck="false" title="<?= esc_attr__('Full http or https address of the blog settings screen in your panel', 'react-bridge') ?>"<?= $inv('panel_url') ?>>
              <p class="rb-hint" data-hint-for="rb-panel-url" role="alert" hidden></p>
              <?= $err('panel_url') ?>
            </div>
          </div></div>
        </details>
      </section>

      <div class="rb-savebar" id="rb-savebar">
        <p class="rb-save-state" id="rb-save-state" aria-live="polite" data-dirty="false"><?= esc_html__('All saved', 'react-bridge') ?></p>
        <div class="rb-save-actions">
          <button type="button" class="rb-btn rb-btn-ghost" id="rb-flush"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Clear cache', 'react-bridge') ?></span></button>
          <?php submit_button(__('Save', 'react-bridge'), 'rb-btn rb-btn-primary', 'submit', false, ['id' => 'rb-submit']); ?>
        </div>
      </div>
    </form>

    <!-- ================= Status ================= -->
    <section id="rb-status" class="rb-panel" role="tabpanel" aria-labelledby="rb-tab-status" tabindex="0" hidden>
      <section class="rb-card" style="--i:0" aria-labelledby="rb-h-endpoints">
        <header class="rb-card-head"><h2 id="rb-h-endpoints"><?= esc_html__('Endpoints', 'react-bridge') ?></h2></header>
        <ul class="rb-endpoints">
          <?php foreach ($endpoints as [$methods, $path, $desc, $test]): ?>
            <li class="rb-ep">
              <span class="rb-ep-methods"><?php foreach ($methods as $m): ?><span class="rb-method rb-method-<?= esc_attr(strtolower($m)) ?>"><?= esc_html($m) ?></span><?php endforeach; ?></span>
              <code class="rb-ep-path" dir="ltr"><?= esc_html($path) ?></code>
              <span class="rb-ep-desc"><?= esc_html($desc) ?></span>
              <span class="rb-ep-action">
                <?php if ($test === false): ?>
                  <span class="rb-ep-lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?= esc_html__('Admins only', 'react-bridge') ?></span>
                <?php elseif ($test): ?>
                  <button type="button" class="rb-btn rb-btn-ghost rb-btn-sm" data-try="<?= esc_attr($test) ?>" aria-label="<?= esc_attr(sprintf(__('Test %s', 'react-bridge'), $path)) ?>"><?= esc_html__('Test', 'react-bridge') ?></button>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="rb-card" id="rb-tester" style="--i:1" aria-labelledby="rb-h-test">
        <header class="rb-card-head"><h2 id="rb-h-test"><?= esc_html__('Live test', 'react-bridge') ?></h2></header>
        <div class="rb-card-body">
          <div class="rb-test">
            <div class="rb-field">
              <label for="rb-ep">Endpoint</label>
              <select id="rb-ep" dir="ltr">
                <option value="/manifest">/manifest</option>
                <option value="/posts">/posts</option>
                <option value="/taxonomies">/taxonomies</option>
                <option value="/health">/health</option>
                <option value="/sitemap.xml">/sitemap.xml</option>
                <option value="/render?path=<?= esc_attr($bp) ?>">/render</option>
              </select>
            </div>
            <div class="rb-field rb-grow">
              <label for="rb-q"><?= esc_html__('Parameters', 'react-bridge') ?></label>
              <input id="rb-q" dir="ltr" placeholder="per_page=3&amp;search=hello" autocomplete="off" spellcheck="false">
            </div>
            <button type="button" class="rb-btn rb-btn-primary" id="rb-run"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><span class="rb-btn-label"><?= esc_html__('Run', 'react-bridge') ?></span></button>
          </div>

          <div class="rb-result" id="rb-result" data-state="empty">
            <div class="rb-result-meta" aria-live="polite">
              <span class="rb-pill" id="rb-res-status"></span>
              <span class="rb-meta" id="rb-res-time"></span>
              <span class="rb-meta" id="rb-res-size"></span>
              <span class="rb-meta" id="rb-res-type" dir="ltr"></span>
            </div>
            <div class="rb-url-row">
              <code id="rb-url" dir="ltr"></code>
              <button type="button" class="rb-icon-btn" id="rb-url-copy" aria-label="<?= esc_attr__('Copy request URL', 'react-bridge') ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
            </div>
            <pre id="rb-out" tabindex="0"><?= esc_html__('Pick an endpoint and run it.', 'react-bridge') ?></pre>
          </div>
        </div>
      </section>
    </section>

    <!-- ================= Guide ================= -->
    <section id="rb-docs" class="rb-panel" role="tabpanel" aria-labelledby="rb-tab-docs" tabindex="0" hidden>
      <div class="rb-docs">
        <nav class="rb-toc" aria-label="<?= esc_attr__('Guide contents', 'react-bridge') ?>">
          <p class="rb-toc-title"><?= esc_html__('In this guide', 'react-bridge') ?></p>
          <ol>
            <li><a href="#rb-doc-1"><?= esc_html__('Base URL', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-2"><?= esc_html__('React client', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-3"><?= esc_html__('SEO in React', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-4"><?= esc_html__('Rendering for bots', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-5"><?= esc_html__('Revalidation webhook', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-6"><?= esc_html__('Response shape', 'react-bridge') ?></a></li>
            <li><a href="#rb-doc-7"><?= esc_html__('Panel connection', 'react-bridge') ?></a></li>
          </ol>
        </nav>
        <div class="rb-doc">
          <h3 id="rb-doc-1"><?= esc_html(sprintf(__('%s. Base URL', 'react-bridge'), $num(1))) ?></h3>
          <div class="rb-kv-row"><span class="rb-kv-label">API</span><code dir="ltr"><?= esc_html($base) ?></code></div>
          <div class="rb-kv-row"><span class="rb-kv-label"><?= esc_html__('Manifest', 'react-bridge') ?></span><code dir="ltr"><?= esc_html($base) ?>/manifest</code></div>
          <?= $code('.env', "VITE_RB_API=__BASE__\nVITE_RB_KEY=__KEY__   # only when \"Require API key\" is on", true) ?>

          <h3 id="rb-doc-2"><?= esc_html(sprintf(__('%s. React client', 'react-bridge'), $num(2))) ?></h3>
          <p><?= esc_html__('One file for every endpoint. It honours ETag and keeps responses in memory.', 'react-bridge') ?></p>
          <?= $code('src/lib/rb.js', <<<'JS'
const BASE = import.meta.env.VITE_RB_API;
const KEY  = import.meta.env.VITE_RB_KEY;
const mem  = new Map();

async function get(path, params = {}) {
  const url = new URL(BASE + path);
  Object.entries(params).forEach(([k, v]) => v != null && v !== '' && url.searchParams.set(k, v));
  const cached = mem.get(url.href);
  const res = await fetch(url, { headers: { ...(KEY && { 'X-RB-Key': KEY }), ...(cached && { 'If-None-Match': cached.etag }) } });
  if (res.status === 304 && cached) return cached.data;
  if (res.status === 404) throw Object.assign(new Error('NOT_FOUND'), { status: 404 });
  if (!res.ok) throw new Error(`RB ${res.status}`);
  const data = await res.json();
  mem.set(url.href, { etag: res.headers.get('ETag'), data });
  return data;
}

export const rb = {
  manifest: ()                => get('/manifest'),
  posts:    (params = {})     => get('/posts', params),      // page, per_page, category, tag, search
  post:     slug              => get(`/posts/${encodeURIComponent(slug)}`),
  page:     slug              => get(`/pages/${encodeURIComponent(slug)}`),
  taxonomies: ()              => get('/taxonomies'),
};
JS) ?>

          <?= $code('src/hooks/useRb.js', <<<'JS'
import { useEffect, useState } from 'react';

export function useRb(fn, deps = []) {
  const [s, set] = useState({ data: null, loading: true, error: null });
  useEffect(() => {
    let live = true;
    set(x => ({ ...x, loading: true, error: null }));
    fn().then(data => live && set({ data, loading: false, error: null }))
        .catch(error => live && set({ data: null, loading: false, error }));
    return () => { live = false; };
  }, deps); // eslint-disable-line
  return s;
}
JS) ?>

          <h3 id="rb-doc-3"><?= esc_html(sprintf(__('%s. SEO in React', 'react-bridge'), $num(3))) ?></h3>
          <p><?php printf(
            esc_html__('Every post ships a ready %1$s. Inject it into %2$s with %3$s (%4$s).', 'react-bridge'),
            '<code>seo.head_html</code>',
            '<code>&lt;head&gt;</code>',
            '<code>react-helmet-async</code>',
            '<code dir="ltr">npm i react-helmet-async dompurify</code>'
          ); ?></p>
          <?= $code('src/components/Seo.jsx', <<<'JS'
import { Helmet } from 'react-helmet-async';

export default function Seo({ seo }) {
  if (!seo) return null;
  return (
    <Helmet>
      <title>{seo.title}</title>
      <meta name="description" content={seo.description} />
      <meta name="robots" content={seo.robots} />
      <link rel="canonical" href={seo.canonical} />
      {Object.entries(seo.og).map(([k, v]) => v && <meta key={k} property={`og:${k}`} content={v} />)}
      {Object.entries(seo.twitter).map(([k, v]) => v && <meta key={k} name={`twitter:${k}`} content={v} />)}
      <script type="application/ld+json">{JSON.stringify(seo.jsonld)}</script>
    </Helmet>
  );
}
JS) ?>

          <?= $code('src/pages/BlogPost.jsx', <<<'JS'
import { useParams, Link } from 'react-router-dom';
import DOMPurify from 'dompurify';
import { rb } from '../lib/rb';
import { useRb } from '../hooks/useRb';
import Seo from '../components/Seo';

export default function BlogPost() {
  const { slug } = useParams();
  const { data: p, loading, error } = useRb(() => rb.post(slug), [slug]);
  if (loading) return <p>Loading...</p>;
  if (error?.status === 404) return <p>Post not found.</p>;
  if (error) return <p>Could not load the post.</p>;
  return (
    <article>
      <Seo seo={p.seo} />
      <h1 dangerouslySetInnerHTML={{ __html: p.title_html }} />
      <p>{new Date(p.date).toLocaleDateString()}, {p.author.name}, {p.reading_time} min</p>
      {p.image && <img src={p.image.src} srcSet={p.image.srcset ?? undefined} alt={p.image.alt} width={p.image.width} height={p.image.height} />}
      <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(p.content) }} />
      <nav>{p.prev && <Link to={`/blog/${p.prev.slug}`}>{p.prev.title}</Link>}{p.next && <Link to={`/blog/${p.next.slug}`}>{p.next.title}</Link>}</nav>
    </article>
  );
}
JS) ?>

          <?= $code('src/pages/BlogList.jsx', <<<'JS'
import { Link, useSearchParams } from 'react-router-dom';
import { rb } from '../lib/rb';
import { useRb } from '../hooks/useRb';

export default function BlogList() {
  const [sp, setSp] = useSearchParams();
  const page = Number(sp.get('page') || 1), category = sp.get('category'), tag = sp.get('tag'), search = sp.get('q');
  const { data, loading, error } = useRb(() => rb.posts({ page, category, tag, search }), [page, category, tag, search]);
  if (loading) return <p>Loading...</p>;
  if (error) return <p>Could not load the posts.</p>;
  return (
    <main>
      <h1>Blog</h1>
      {data.items.map(p => (
        <Link key={p.id} to={`/blog/${p.slug}`}>
          {p.image && <img src={p.image.src} alt={p.image.alt} loading="lazy" />}
          <h2>{p.title}</h2><p>{p.excerpt}</p>
        </Link>
      ))}
      {data.total_pages > 1 && <nav>
        <button disabled={page <= 1} onClick={() => setSp({ ...Object.fromEntries(sp), page: page - 1 })}>Previous</button>
        <span>{page} / {data.total_pages}</span>
        <button disabled={page >= data.total_pages} onClick={() => setSp({ ...Object.fromEntries(sp), page: page + 1 })}>Next</button>
      </nav>}
    </main>
  );
}
JS) ?>

          <?= $code(__('src/main.jsx (changes)', 'react-bridge'), <<<'JS'
import { HelmetProvider } from 'react-helmet-async';
// ...
<HelmetProvider>
  <BrowserRouter>
    <Routes>
      <Route path="/blog" element={<BlogList />} />
      <Route path="/blog/:slug" element={<BlogPost />} />
    </Routes>
  </BrowserRouter>
</HelmetProvider>
JS) ?>

          <h3 id="rb-doc-4"><?= esc_html(sprintf(__('%s. Dynamic rendering for bots (Nginx)', 'react-bridge'), $num(4))) ?></h3>
          <p><?= esc_html__('Search and social bots get full HTML from the plugin instead of an empty SPA, while people still get React. Add this block on the frontend server:', 'react-bridge') ?></p>
          <?= $code(__('nginx: frontend server block', 'react-bridge'), <<<NGX
# sitemap from the plugin
location = /sitemap.xml {
    proxy_pass {$base}/sitemap.xml;
    proxy_set_header Host {$_SERVER['HTTP_HOST']};
}

# bot detection
map \$http_user_agent \$rb_bot {
    default 0;
    ~*(googlebot|bingbot|yandex|duckduckbot|baiduspider|facebookexternalhit|twitterbot|linkedinbot|telegrambot|whatsapp|slackbot|discordbot|applebot|ahrefsbot|semrushbot) 1;
}

# blog pages
location ~ ^{$bp}(/.*)?\$ {
    if (\$rb_bot) {
        proxy_pass {$base}/render?path=\$uri;
    }
    try_files \$uri /index.html;
}
NGX) ?>
          <p class="rb-note"><?php printf(
            esc_html__('The %1$s block must sit at %2$s level, not inside %3$s. Behind Cloudflare, enable caching for %4$s.', 'react-bridge'),
            '<code>map</code>',
            '<code>http { }</code>',
            '<code>server</code>',
            '<code dir="ltr">/rb/v1/render</code>'
          ); ?></p>
          <p><?php printf(
            esc_html__('Without touching Nginx: prerender the sitemap routes at build time with %1$s or %2$s.', 'react-bridge'),
            '<code>vite-ssg</code>',
            '<code>vite-plugin-prerender</code>'
          ); ?></p>

          <h3 id="rb-doc-5"><?= esc_html(sprintf(__('%s. Revalidation webhook (optional)', 'react-bridge'), $num(5))) ?></h3>
          <p><?php printf(
            esc_html__('For Next.js ISR or a CDN cache, the configured URL is POSTed at most once per request for each post or page. %1$s is one of %2$s (first publish), %3$s (edit of a published post), %4$s (moved to draft, trash or private) and %5$s (permanent delete of a published post).', 'react-bridge'),
            '<code>event</code>',
            '<code>publish</code>',
            '<code>update</code>',
            '<code>unpublish</code>',
            '<code>delete</code>'
          ); ?></p>
          <?= $code('payload', <<<'JSON'
{ "event": "publish", "type": "post", "slug": "hello", "url": "https://example.com/blog/hello", "time": 1757500000 }
JSON) ?>
          <?= $code(__('Signature check (Node)', 'react-bridge'), <<<'JS'
import crypto from 'node:crypto';
const ok = crypto.timingSafeEqual(
  Buffer.from(req.headers['x-rb-signature'], 'hex'),
  Buffer.from(crypto.createHmac('sha256', process.env.RB_SECRET).update(rawBody).digest('hex'), 'hex'),
);
JS) ?>

          <h3 id="rb-doc-6"><?= esc_html(sprintf(__('%s. Response shape of a post', 'react-bridge'), $num(6))) ?></h3>
          <?= $code('GET /posts/{slug}', <<<'JSON'
{
  "id": 12, "type": "post", "slug": "hello", "url": "https://example.com/blog/hello",
  "date": "2026-09-10T10:00:00+03:30", "modified": "…",
  "title": "…", "title_html": "…", "excerpt": "…", "content": "<p>…</p>", "reading_time": 4,
  "author": { "name": "…", "avatar": "…" },
  "image": { "src": "…", "full": "…", "srcset": "…", "alt": "…", "width": 1200, "height": 800 },
  "categories": [{ "id": 3, "name": "…", "slug": "…" }], "tags": [],
  "seo": { "title": "…", "description": "…", "canonical": "…", "robots": "…", "og": {…}, "twitter": {…}, "jsonld": {…}, "head_html": "<title>…" },
  "prev": { "slug": "…", "title": "…", "url": "…" }, "next": null
}
JSON) ?>

          <h3 id="rb-doc-7"><?= esc_html(sprintf(__('%s. Panel connection', 'react-bridge'), $num(7))) ?></h3>
          <p><?php printf(
            esc_html__('An external admin panel reads and changes the blog settings through the %1$s endpoint. Only these %2$s keys are reachable this way. The API key, the webhook secret and the SEO settings are never returned.', 'react-bridge'),
            '<code>blog-settings</code>',
            esc_html($num(7))
          ); ?></p>
          <div class="rb-kv-row"><span class="rb-kv-label"><?= esc_html__('Read', 'react-bridge') ?></span><code dir="ltr">GET <?= esc_html($base) ?>/blog-settings</code></div>
          <div class="rb-kv-row"><span class="rb-kv-label"><?= esc_html__('Partial update', 'react-bridge') ?></span><code dir="ltr">PATCH <?= esc_html($base) ?>/blog-settings</code></div>
          <p><strong><?= esc_html__('Authentication:', 'react-bridge') ?></strong> <?php printf(
            esc_html__('an application password of an administrator. Under Users, Profile, Application Passwords create one named "React Bridge" and send it with Basic Auth: %1$s. Use HTTPS in production. The %2$s key is not accepted on this route because it is public inside the frontend build. Without authentication or administrator rights the answer is %3$s or %4$s.', 'react-bridge'),
            '<code dir="ltr">Authorization: Basic base64(username:application-password)</code>',
            '<code dir="ltr">X-RB-Key</code>',
            '<code>401</code>',
            '<code>403</code>'
          ); ?></p>
          <div class="rb-table-wrap">
            <table class="rb-table">
              <thead><tr><th scope="col"><?= esc_html__('Key', 'react-bridge') ?></th><th scope="col"><?= esc_html__('Type', 'react-bridge') ?></th><th scope="col"><?= esc_html__('Rule', 'react-bridge') ?></th></tr></thead>
              <tbody>
                <tr><td><code>frontend_url</code></td><td>string</td><td><?php printf(
                  esc_html__('%1$s or a full %2$s address, no trailing slash, no query, fragment or userinfo. A path is allowed.', 'react-bridge'),
                  '<code>""</code>',
                  '<code>http(s)</code>'
                ); ?></td></tr>
                <tr><td><code>blog_path</code></td><td>string</td><td><?php printf(
                  esc_html__('Starts with a slash, no trailing slash, no spaces and no %1$s or %2$s. Default %3$s.', 'react-bridge'),
                  '<code>?</code>',
                  '<code>#</code>',
                  '<code>/blog</code>'
                ); ?></td></tr>
                <tr><td><code>redirect_to_frontend</code></td><td>boolean</td><td><?= esc_html__('301 redirect from old WordPress pages to the frontend.', 'react-bridge') ?></td></tr>
                <tr><td><code>cors_origins</code></td><td>string[]</td><td><?php printf(
                  esc_html__('Each entry exactly %1$s, no path. Up to %2$s entries and no %3$s. The frontend origin is allowed automatically and is not stored in this list.', 'react-bridge'),
                  '<code dir="ltr">scheme://host[:port]</code>',
                  esc_html($num(50)),
                  '<code>*</code>'
                ); ?></td></tr>
                <tr><td><code>cache_ttl_seconds</code></td><td>integer</td><td><?= esc_html(sprintf(__('%1$s to %2$s. Zero means no cache.', 'react-bridge'), $num(0), $num(86400))) ?></td></tr>
                <tr><td><code>posts_per_page</code></td><td>integer</td><td><?= esc_html(sprintf(__('%1$s to %2$s.', 'react-bridge'), $num(1), $num(50))) ?></td></tr>
                <tr><td><code>revalidate_webhook_url</code></td><td>string</td><td><?php printf(
                  esc_html__('%1$s or a full %2$s address.', 'react-bridge'),
                  '<code>""</code>',
                  '<code>http(s)</code>'
                ); ?></td></tr>
              </tbody>
            </table>
          </div>
          <p><?php printf(
            esc_html__('In %1$s send only the keys that must change. Types must match exactly (%2$s, not %3$s). If even one field is invalid nothing is saved. Unknown keys are ignored and reported in the %4$s header. If the client cannot send PATCH, use %5$s with the %6$s header.', 'react-bridge'),
            '<code>PATCH</code>',
            '<code>300</code>',
            '<code>"300"</code>',
            '<code dir="ltr">X-RB-Ignored-Fields</code>',
            '<code>POST</code>',
            '<code dir="ltr">X-HTTP-Method-Override: PATCH</code>'
          ); ?></p>
          <?= $code(__('curl: read and update', 'react-bridge'), <<<'SH'
curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' __BASE__/blog-settings

curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' -X PATCH __BASE__/blog-settings \
  -H 'Content-Type: application/json' \
  -d '{"frontend_url":"https://example.com","posts_per_page":12,"cors_origins":["https://panel.example.com"]}'
SH, true) ?>
          <?= $code(__('Successful GET and PATCH response (200)', 'react-bridge'), <<<'JSON'
{
  "frontend_url": "https://example.com",
  "blog_path": "/blog",
  "redirect_to_frontend": false,
  "cors_origins": ["https://panel.example.com"],
  "cache_ttl_seconds": 300,
  "posts_per_page": 12,
  "revalidate_webhook_url": ""
}
JSON) ?>
          <?= $code(__('Validation error (400)', 'react-bridge'), <<<'JSON'
{
  "code": "rb_invalid_settings",
  "message": "Invalid settings.",
  "data": {
    "status": 400,
    "errors": { "frontend_url": "…", "posts_per_page": "…" }
  }
}
JSON) ?>
          <p class="rb-note"><?php printf(
            esc_html__('Each field message in %1$s is short and can be shown directly under that field in your panel. A body that is not a JSON object is rejected with code %2$s and status %3$s. Responses carry %4$s.', 'react-bridge'),
            '<code>data.errors</code>',
            '<code>rb_invalid_body</code>',
            '<code>400</code>',
            '<code dir="ltr">Cache-Control: no-store</code>'
          ); ?></p>
        </div>
      </div>
    </section>
  </div>
</div>
