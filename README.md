[English](README.md) | [فارسی](README.fa.md)

# React Bridge

Headless WordPress for any React or JavaScript frontend. One REST namespace (`rb/v1`) delivers posts, pages, taxonomies, menus, per-post SEO (meta, Open Graph, JSON-LD), crawler-ready HTML, a sitemap, server-side caching with ETags, and a signed revalidation webhook.

## Screenshots

<table>
  <tr>
    <td width="49%"><img src="docs/screenshots/01-wizard-fa.png" alt="Quick-start wizard"></td>
    <td width="49%"><img src="docs/screenshots/02-connect-fa.png" alt="Connection tab"></td>
  </tr>
  <tr>
    <td width="49%"><img src="docs/screenshots/03-advanced-fa.png" alt="Advanced tab"></td>
    <td width="49%"><img src="docs/screenshots/04-status-fa.png" alt="Status tab with the live tester"></td>
  </tr>
  <tr>
    <td width="49%"><img src="docs/screenshots/05-guide-fa.png" alt="Guide tab"></td>
    <td width="49%"><img src="docs/screenshots/06-connect-en.png" alt="English LTR view"></td>
  </tr>
</table>

<img src="docs/screenshots/07-mobile-fa.png" alt="Mobile width" width="320">

## Features

- **Content API**: `/posts` (paginated, filterable), `/posts/{slug}`, `/pages/{slug}`, `/taxonomies`, `/manifest`, `/health`.
- **SEO built in**: every post ships a ready `seo` object and `head_html`; reads overrides from the plugin metabox, Yoast or Rank Math.
- **Crawler rendering**: `/render?path=` returns full HTML for bots; drop-in nginx sample in the admin guide.
- **Sitemap** with frontend URLs, capped at 50,000 entries, noindex-aware.
- **Cache**: transient cache keyed by generation, `Cache-Control` and `ETag`/`304` handling, flushed only on relevant content changes.
- **Webhook**: `publish`, `update`, `unpublish`, `delete` events, HMAC-SHA256 signed, sent once per post per request.
- **Admin panel contract**: `GET/PATCH /blog-settings` for an external admin panel (Application Password auth), plus one-click copy/paste of settings between WordPress and your panel.
- **Quick-start wizard**: enter the frontend URL and the plugin allows its origin, probes it, and checks the connection.
- **Universal**: translatable (English source, Persian translation included), follows the site text direction, no vendor-specific assumptions.

## Requirements

WordPress 6.2+, PHP 8.0+.

## Install

1. Download the release zip (or clone this repository into `wp-content/plugins/react-bridge`).
2. Activate **React Bridge** in Plugins.
3. Open **React Bridge** in the admin menu and follow the quick setup.

## Frontend

The admin **Guide** tab contains ready-to-use code: a small fetch client with ETag support, a React hook, list and article pages, an SEO component and the nginx block for crawler rendering.

## Development

```bash
# run the CLI test suite against a local WordPress install
php tests/run.php /path/to/wordpress

# regenerate translations after changing source strings
php tools/extract-pot.php
php tools/make-mo.php languages/react-bridge-fa_IR.po
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
