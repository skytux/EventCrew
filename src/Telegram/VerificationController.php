<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Support\TelegramLogin;
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
    /** The confirmed person's language, for the page that follows; null for English. */
    private ?string $pageLanguage = null;

    public function __construct(
        private readonly AuthTokenRepository $tokens,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        // Optional: without it a click never signs in a waiting browser.
        private readonly ?TelegramLogin $webLogin = null
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
        echo Language::run($this->pageLanguage, fn (): string => $this->renderPage($confirmed));
        exit;
    }

    /**
     * Consumes the token and, if it was valid, marks the person verified, links
     * the Telegram account that asked for the link, and nudges them back to the
     * board over Telegram. Split from handle() so the decision is testable
     * without the echo/exit that serving a page needs.
     */
    public function verify(string $token): bool
    {
        $personId = $this->tokens->consume($token, OnboardingService::VERIFY_PURPOSE);

        if (null === $personId) {
            return false;
        }

        $pending = OnboardingService::takePendingLink($token);
        $linked = null !== $pending
            && $this->linkWaitingTelegram($personId, $pending['telegram_user_id'], $pending['telegram_chat_id']);

        $this->people->markEmailVerified($personId);

        $person = $this->people->find($personId);
        $this->pageLanguage = $person?->language;

        // A browser waiting on the crew app's Telegram button signs in now,
        // when this Telegram account pressed it before signing up.
        if ($linked && null !== $this->webLogin && null !== $person && WebLoginBot::canSignIn($person)) {
            $this->webLogin->approveWaiting($pending['telegram_user_id'], $personId);
        }

        if (null !== $person && null !== $person->telegramChatId) {
            $chatId = $person->telegramChatId;
            Language::forPerson($person, fn () => $this->telegram->sendMessage(
                $chatId,
                __('Your email is confirmed. Tap a task on the board to sign up.', 'eventcrew')
            ));
        }

        return true;
    }

    /**
     * Links the Telegram account that typed this email, now that the click has
     * proved the email is theirs: fills an empty link, replaces a different one.
     *
     * A Telegram account already linked to another person is refused rather
     * than moved: the column is unique, and moving it would splice two people's
     * histories - and their earned credits - onto one account. When an old
     * link is replaced, its chat is told, so a takeover cannot pass unseen.
     *
     * True when the Telegram account is now linked to this person.
     */
    private function linkWaitingTelegram(int $personId, int $telegramUserId, int $chatId): bool
    {
        $person = $this->people->find($personId);

        if (null === $person) {
            return false;
        }

        $holder = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $holder && $holder->id !== $personId) {
            Language::forPerson($holder, fn () => $this->telegram->sendMessage(
                $chatId,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('This Telegram account is already linked to another email. Contact an organizer if that is wrong.', 'eventcrew')
            ));

            return false;
        }

        $oldChatId = $person->telegramUserId !== $telegramUserId ? $person->telegramChatId : null;

        $this->people->linkTelegram($personId, $telegramUserId, $chatId);

        // Sent after the link is written, so a failed send cannot undo it;
        // TelegramClient logs a failure instead of throwing.
        if (null !== $person->telegramUserId && null !== $oldChatId) {
            Language::forPerson($person, fn () => $this->telegram->sendMessage(
                $oldChatId,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Your EventCrew account is now linked to another Telegram account. Contact an organizer if that wasn't you.", 'eventcrew')
            ));
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

        // The page is written in the person's language; outside a run() that is
        // WordPress's own.
        $lang = Language::current() ?? str_replace('_', '-', (string) get_bloginfo('language'));

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="%3$s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em;text-align:center"><h1>%1$s</h1><p>%2$s</p></body></html>',
            esc_html($heading),
            esc_html($detail),
            esc_attr($lang)
        );
    }
}
