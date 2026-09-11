<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-only blog-settings contract: GET / PATCH {rest}/rb/v1/blog-settings.
 *
 * Auth is the WordPress capability manage_options (cookie + X-WP-Nonce or an Application
 * Password), never X-RB-Key: that key ships in the public frontend bundle.
 * No route `args` on purpose - WordPress would coerce types before our strict validation.
 */
final class RB_Settings_Rest
{
    const ROUTE = '/blog-settings';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(RB_NS, self::ROUTE, [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'read'], 'permission_callback' => [self::class, 'permission']],
            ['methods' => 'PATCH', 'callback' => [self::class, 'patch'], 'permission_callback' => [self::class, 'permission']],
            'schema' => [self::class, 'schema'],
        ]);
    }

    public static function permission()
    {
        if (current_user_can('manage_options')) return true;
        return new WP_Error('rest_forbidden', __('You do not have permission to do this.', 'react-bridge'), ['status' => rest_authorization_required_code()]);
    }

    public static function read(): WP_REST_Response
    {
        return self::respond(RB_Settings::contract());
    }

    /** Partial, atomic update: one invalid field > 400 with every field error and nothing saved. */
    public static function patch(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if ($body === null && !$request->is_json_content_type()) $body = $request->get_body_params();
        if (!is_array($body) || ($body !== [] && array_keys($body) === range(0, count($body) - 1))) {
            return new WP_Error('rb_invalid_body', __('The request body must be a JSON object.', 'react-bridge'), ['status' => 400]);
        }

        [$clean, $errors, $ignored] = RB_Settings::validate($body, true);
        $user = get_current_user_id();
        if ($errors) {
            error_log(sprintf('[react-bridge] blog-settings update rejected for user #%d: fields=%s', $user, implode(',', array_keys($errors))));
            return new WP_Error('rb_invalid_settings', __('The settings are not valid.', 'react-bridge'), ['status' => 400, 'errors' => $errors]);
        }

        if ($clean) RB_Settings::update($clean);
        // Audit trail: key names only - values may carry secrets (e.g. a token in the webhook URL).
        error_log(sprintf('[react-bridge] blog-settings updated by user #%d: keys=%s', $user, implode(',', array_keys($clean))));

        $res   = self::respond(RB_Settings::contract());
        $names = self::header_safe($ignored);
        if ($names) $res->header('X-RB-Ignored-Fields', implode(',', $names));
        return $res;
    }

    /** Documentation only (OPTIONS / ?context=help). Not used for argument coercion. */
    public static function schema(): array
    {
        $url = ['type' => 'string'];
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'rb-blog-settings',
            'type'       => 'object',
            'properties' => [
                'frontend_url'           => $url + ['description' => "'' or absolute http(s) URL; no trailing slash, query, fragment or userinfo."],
                'blog_path'              => ['type' => 'string', 'pattern' => '^(/[^\s/?#]+)+$', 'description' => 'Blog path on the frontend, e.g. /blog.'],
                'redirect_to_frontend'   => ['type' => 'boolean', 'description' => 'Old server-side WordPress pages 301 to the frontend.'],
                'cors_origins'           => ['type' => 'array', 'maxItems' => RB_Settings::MAX_ORIGINS, 'items' => ['type' => 'string'], 'description' => 'scheme://host[:port] only; the frontend_url origin is always allowed.'],
                'cache_ttl_seconds'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => RB_Settings::MAX_TTL, 'description' => '0 disables caching.'],
                'posts_per_page'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => RB_Settings::MAX_PER_PAGE],
                'revalidate_webhook_url' => $url + ['description' => "'' or absolute http(s) URL; receives a signed POST on publish/update/unpublish/delete."],
            ],
        ];
    }

    private static function respond(array $data): WP_REST_Response
    {
        $res = new WP_REST_Response($data, 200);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /** Client-supplied names go into a response header: keep a safe charset and a bounded size. */
    private static function header_safe(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = substr((string) preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $name), 0, 64);
            if ($name !== '') $out[$name] = true;
            if (count($out) >= 20) break;
        }
        return array_keys($out);
    }
}
