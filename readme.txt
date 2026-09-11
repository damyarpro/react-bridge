=== React Bridge ===
Contributors: reactbridge
Tags: headless, react, rest api, seo, sitemap
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
Text Domain: react-bridge
Domain Path: /languages

Headless WordPress for any JavaScript frontend: one API namespace (rb/v1) with posts, pages, taxonomies, menus, SEO (meta, OG, JSON-LD), dynamic rendering for crawlers, sitemap, CORS, optional API key, server cache with ETag, a signed revalidate webhook and an admin-only blog-settings API.

== Installation ==
1. Upload the react-bridge folder to wp-content/plugins/ and activate.
2. Open React Bridge in the admin menu, set the frontend URL, save.
3. Follow the "Guide and code" tab.

== Languages ==
Every screen and message goes through WordPress translation with English source text and the text domain react-bridge. Translations live in the languages/ folder; a Persian (fa_IR) translation ships with the plugin. The admin screen follows the site text direction, so it mirrors correctly on both LTR and RTL sites.

== Admin panel (optional) ==
If the blog settings are also edited from an external admin panel, set its name and the URL of its blog-settings screen under Advanced settings (panel_name, panel_url). Both are optional: when panel_url is empty the panel links stay hidden. Settings can be copied to the panel as JSON and pasted back; pasting applies them through the validated blog-settings API.

== Blog settings API ==
Admin-only endpoint for reading and changing the seven blog settings.

* GET   /wp-json/rb/v1/blog-settings - returns the 7 settings below. Response header: Cache-Control: no-store.
* PATCH /wp-json/rb/v1/blog-settings - partial update with a JSON object body; returns the 7 settings after saving. Clients that cannot send PATCH may send POST with the header X-HTTP-Method-Override: PATCH.

Authentication: a user with the manage_options capability, via an Application Password (HTTP Basic auth; HTTPS in production) or a logged-in cookie plus X-WP-Nonce. The public X-RB-Key is not accepted here. Anonymous requests get 401, logged-in users without the capability get 403.

Keys and rules (types are strict: JSON booleans and integers, "1" or "300" are rejected):

* frontend_url (string) - "" or an absolute http(s) URL; no trailing slash, query, fragment or userinfo; a path is allowed (https://example.com/en).
* blog_path (string) - starts with "/", no trailing slash, whitespace, empty segments, "?" or "#". Default /blog.
* redirect_to_frontend (boolean) - old server-side WordPress pages answer 301 to the frontend.
* cors_origins (string[]) - each exactly scheme://host[:port] (http/https, no path, not even "/"), stored lowercase, de-duplicated, max 50, no "*". The origin of frontend_url is always allowed automatically.
* cache_ttl_seconds (integer 0..86400) - Cache-Control and ETag lifetime; 0 disables caching.
* posts_per_page (integer 1..50) - default 10.
* revalidate_webhook_url (string) - "" or an absolute http(s) URL; receives a signed POST after publish/edit/delete.

Validation is atomic: when any field is invalid nothing is saved and the response is 400:

    {"code":"rb_invalid_settings","message":"The settings are not valid.","data":{"status":400,"errors":{"posts_per_page":"..."}}}

A body that is not a JSON object returns 400 rb_invalid_body. Unknown fields are ignored and listed in the X-RB-Ignored-Fields response header. Every successful update writes one line to the PHP error log with the user ID and the changed key names (never the values).

== Tests ==
CLI only (the tests directory is denied over HTTP): php tests/run.php [path-to-wordpress-root]
The plugin must be active. rb_settings is snapshotted at start and restored after each test and at shutdown; the cache generation is moved past every value used during the run.

== Changelog ==
= 1.3.0 =
* New: the plugin is site agnostic. No vendor names anywhere; the external admin panel is an optional, generic concept configured with panel_name and panel_url.
* New: full internationalisation. Every user-facing string in PHP and JavaScript uses the react-bridge text domain with English source text; a Persian (fa_IR) translation ships in languages/.
* New: the admin screen follows the site text direction and mirrors correctly on LTR sites.
* New: settings panel_name (max 60 characters) and panel_url ("" or an absolute http(s) URL), validated with the same rule as the webhook URL.
* Changed: the default content locale is the site locale instead of a fixed value; placeholders and examples use example.com.
* Changed: the export helper is now RB_Settings::panel_export().
* Unchanged: the REST contract, option keys, field names, AJAX actions and the signed webhook format.

= 1.2.0 =
* New: quick-start wizard on the settings screen (frontend address, copy settings to the panel, connection checks). It reappears automatically when the frontend URL is empty or still points at this WordPress.
* New: admin-only AJAX actions rb_onboarding (finish or restart the wizard) and rb_probe_frontend (reachability check of the entered frontend address); both require a nonce and manage_options.
* New: option rb_onboarding_done (autoloaded, removed on uninstall).
* Changed: the settings screen is task shaped: two connect cards, advanced settings collapsed into groups, explanatory text only in the guide tab.

= 1.1.0 =
* New: admin-only GET/PATCH /rb/v1/blog-settings with strict validation, per-field errors and an audit log line.
* Changed: settings keys follow the blog-settings contract: redirect_wp to redirect_to_frontend, origins to cors_origins (now a list), cache_ttl to cache_ttl_seconds, per_page to posts_per_page, revalidate_url to revalidate_webhook_url. Existing values are migrated automatically on the first request after the update; invalid legacy values fall back to safe defaults.
* Changed: the settings form uses the same validation rules as the API; an invalid field shows an error and keeps its previous value.
* Changed: the wildcard "*" CORS origin is no longer supported; CORS headers are sent only for rb/v1 routes and allowed origins.
* Changed: server cache is invalidated through a generation counter (option rb_cache_gen) instead of deleting transients with LIKE queries.
* Changed: Cache-Control follows cache_ttl_seconds (no-store when 0, private when the API key is required) and If-None-Match returns 304.
* Changed: revalidate webhook sends publish, update, unpublish and delete events once per post per request; the signature format is unchanged.
* Fixed: sitemap is built without per-post queries; reading time counts non-Latin words correctly.
* Added: CLI test suite in tests/.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 1.3.0 =
Settings and the API are unchanged. The admin screen is now translated and direction aware, and the external admin panel is configured with the new panel_name and panel_url settings instead of being assumed.

= 1.2.0 =
Settings are unchanged. The redesigned screen opens a short quick-start wizard until the frontend URL points at a real frontend.

= 1.1.0 =
Settings are migrated automatically. A "*" entry in allowed origins is dropped; list each frontend origin explicitly.
