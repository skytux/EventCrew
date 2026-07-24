<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\SignedLink;
use WP_REST_Request;

/**
 * The manage-account page every email links to: switch emails off, or delete
 * your data entirely.
 *
 * The signed link is reusable (an unsubscribe link must work in an old email),
 * so the destructive actions are never the bare GET - they are POST buttons on
 * the page, which a mail client's link prefetch cannot trigger. Disable is
 * reversible (via the bot's /start); delete removes the person and their
 * assignments for good.
 */
final class ManageController
{
    public const DISABLE = 'disable';
    public const DELETE = 'delete';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly RedemptionRepository $redemptions,
        private readonly CreditGrantRepository $grants
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
            '/manage',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): void
    {
        $personId = SignedLink::verify('manage', (string) $request->get_param('token'));
        $person = null === $personId ? null : $this->people->find($personId);

        if (null === $person) {
            status_header(404);
            $html = $this->document(
                __('Link expired', 'eventcrew'),
                '<p>' . esc_html__('This link is no longer valid.', 'eventcrew') . '</p>'
            );
        } elseif ('POST' === $request->get_method()) {
            $outcome = $this->apply($person->id, (string) $request->get_param('action'));
            status_header(200);
            $html = $this->confirmationPage($outcome);
        } else {
            status_header(200);
            $html = $this->accountPage($person, (string) $request->get_param('token'));
        }

        header('Content-Type: text/html; charset=utf-8');
        // Built from literal markup and esc_html()'d values in the page builders.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }

    /**
     * Performs a manage action and reports which one happened.
     *
     * @return self::DISABLE|self::DELETE|''
     */
    public function apply(int $personId, string $action): string
    {
        if (self::DISABLE === $action) {
            $this->people->disable($personId);

            return self::DISABLE;
        }

        if (self::DELETE === $action) {
            $this->assignments->deleteForPerson($personId);
            $this->redemptions->deleteForPerson($personId);
            $this->grants->deleteForPerson($personId);
            $this->people->delete($personId);

            return self::DELETE;
        }

        return '';
    }

    private function accountPage(Person $person, string $token): string
    {
        $status = $person->isDisabled()
            ? esc_html__('Your account is currently switched off.', 'eventcrew')
            : esc_html__('Your account is active.', 'eventcrew');

        $body = '<p>' . esc_html($person->email) . '</p><p>' . $status . '</p>'
            . $this->form($token, self::DISABLE, __('Stop all emails', 'eventcrew'), false)
            . $this->form($token, self::DELETE, __('Delete my data', 'eventcrew'), true);

        return $this->document(__('Manage your EventCrew account', 'eventcrew'), $body);
    }

    private function form(string $token, string $action, string $label, bool $danger): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="token" value="%s"><input type="hidden" name="action" value="%s"><button type="submit" style="padding:.6em 1em;%s">%s</button></form>',
            esc_attr($token),
            esc_attr($action),
            $danger ? 'color:#b32d2e' : '',
            esc_html($label)
        );
    }

    private function confirmationPage(string $outcome): string
    {
        $message = match ($outcome) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::DISABLE => __('Done — you won’t get any more emails. Send the bot /start to turn your account back on.', 'eventcrew'),
            self::DELETE => __('Your data has been deleted. Thanks for helping out.', 'eventcrew'),
            default => __('Nothing to do.', 'eventcrew'),
        };

        return $this->document(__('EventCrew', 'eventcrew'), '<p>' . esc_html($message) . '</p>');
    }

    private function document(string $title, string $inner): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em"><h1 style="font-size:1.2em">%1$s</h1>%2$s</body></html>',
            esc_html($title),
            $inner
        );
    }
}
