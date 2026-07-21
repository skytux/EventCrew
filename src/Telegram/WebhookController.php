<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The bot's front door: the endpoint Telegram posts every update to.
 *
 * Telegram cannot send a WordPress nonce, so the request is authenticated by a
 * shared secret - matched in constant time. Everything past the door is handed
 * to the router; the endpoint answers 200 regardless, so a bot action that
 * fails is logged rather than made Telegram's problem to retry.
 *
 * There are two doors to the same room. The REST route under /wp-json is the
 * clean default. But locked-down and shared hosts routinely restrict the REST
 * API to logged-in users (returning 400/403 to Telegram) or strip the secret
 * header at a proxy - so there is also a fallback door on admin-ajax.php, the
 * one endpoint such hosts almost always leave open (countless plugins rely on
 * it for anonymous requests). It reads the raw body itself, so nothing upstream
 * can pre-reject it, and takes the secret in the URL where a header cannot be
 * stripped. Which door Telegram is pointed at is the admin's choice.
 */
final class WebhookController
{
    public const SECRET_OPTION = 'eventcrew_telegram_webhook_secret';

    /** When set, the webhook is installed on the fallback door, not REST. */
    public const USE_FALLBACK_OPTION = 'eventcrew_telegram_use_fallback';

    public const ROUTE_NAMESPACE = 'eventcrew/v1';
    public const ROUTE = '/telegram/webhook';

    /** admin-ajax.php action for the non-REST door. */
    public const FALLBACK_ACTION = 'eventcrew_telegram_webhook';

    /** The header Telegram sends the configured secret token in. */
    private const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __construct(
        private readonly UpdateRouter $router
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('wp_ajax_nopriv_' . self::FALLBACK_ACTION, [$this, 'handleFallback']);
        add_action('wp_ajax_' . self::FALLBACK_ACTION, [$this, 'handleFallback']);
    }

    /**
     * The URL of whichever door is currently selected, for setWebhook and for
     * display. The fallback URL carries the secret as a query argument.
     */
    public static function webhookUrl(string $secret): string
    {
        if ((bool) get_option(self::USE_FALLBACK_OPTION, false)) {
            return add_query_arg(
                ['action' => self::FALLBACK_ACTION, 'token' => $secret],
                admin_url('admin-ajax.php')
            );
        }

        return rest_url(self::ROUTE_NAMESPACE . self::ROUTE);
    }

    // --- REST door ----------------------------------------------------------

    public function registerRoutes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'authorize'],
            ]
        );
    }

    /**
     * With no secret stored the endpoint refuses everything - there is no
     * "unprotected" state a misconfiguration could leave the door open in.
     */
    public function authorize(WP_REST_Request $request): bool
    {
        $secret = trim((string) get_option(self::SECRET_OPTION, ''));

        if ('' === $secret) {
            return false;
        }

        return hash_equals($secret, (string) $request->get_header(self::SECRET_HEADER));
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $update = $request->get_json_params();

        if (is_array($update)) {
            $this->router->dispatch($update);
        }

        return new WP_REST_Response(null, 200);
    }

    // --- fallback door (admin-ajax.php) -------------------------------------

    public function handleFallback(): void
    {
        // The secret is the authentication; a nonce is impossible here because
        // Telegram cannot send one. It is read from the URL first (a proxy
        // cannot strip a query arg the way it can a custom header), then the
        // header as a fallback.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $secret = isset($_GET['token'])
            ? sanitize_text_field(wp_unslash($_GET['token']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ('' === $secret && isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
            $secret = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']));
        }

        $body = (string) file_get_contents('php://input');

        status_header($this->processFallback($secret, $body));
        exit;
    }

    /**
     * The testable core of the fallback door: verify the secret, dispatch the
     * update, and report the HTTP status to send back. 403 on a bad secret so a
     * random prober learns nothing; 200 otherwise, even for a body that isn't a
     * usable update, so Telegram treats it as delivered.
     */
    public function processFallback(string $providedSecret, string $rawBody): int
    {
        $secret = trim((string) get_option(self::SECRET_OPTION, ''));

        if ('' === $secret || ! hash_equals($secret, $providedSecret)) {
            return 403;
        }

        $update = json_decode($rawBody, true);

        if (is_array($update)) {
            $this->router->dispatch($update);
        }

        return 200;
    }
}
