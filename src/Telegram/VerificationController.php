<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use WP_REST_Request;

/**
 * The other end of the onboarding magic link: a public GET endpoint that
 * confirms an email address.
 *
 * It is public on purpose - the whole point is that the link works from the
 * recipient's mail client with nobody logged in - and its only authority is
 * the single-use token, which AuthTokenRepository validates and burns.
 */
final class VerificationController
{
    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/verify',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): void
    {
        $confirmed = $this->verify((string) $request->get_param('token'));

        status_header($confirmed ? 200 : 400);
        header('Content-Type: text/html; charset=utf-8');
        // Assembled from literal markup and esc_html()'d text in renderPage().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->renderPage($confirmed);
        exit;
    }

    /**
     * Consumes the token and, if it was valid, marks the person verified and
     * nudges them back to the board over Telegram. Split from handle() so the
     * decision is testable without the echo/exit that serving a page needs.
     */
    public function verify(string $token): bool
    {
        $personId = $this->tokens->consume($token, OnboardingService::VERIFY_PURPOSE);

        if (null === $personId) {
            return false;
        }

        $this->people->markEmailVerified($personId);

        $person = $this->people->find($personId);

        if (null !== $person && null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                __('Your email is confirmed. Tap a task on the board to sign up.', 'eventcrew')
            );
        }

        return true;
    }

    public function renderPage(bool $confirmed): string
    {
        $heading = $confirmed
            ? __('Email confirmed', 'eventcrew')
            : __('This link is invalid or has expired', 'eventcrew');

        $detail = $confirmed
            ? __('You can return to Telegram and sign up for tasks.', 'eventcrew')
            : __('Ask the bot for a fresh one by sending it /start.', 'eventcrew');

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em;text-align:center"><h1>%1$s</h1><p>%2$s</p></body></html>',
            esc_html($heading),
            esc_html($detail)
        );
    }
}
