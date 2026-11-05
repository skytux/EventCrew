<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailBody;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\SignedLink;
use WP_REST_Request;

/**
 * The manage-account page every email links to (on a Telegram-only install with
 * no public sign-up page): delete your data entirely. Where a sign-up page
 * exists, the email link instead lands on the signed-in web profile, which
 * carries the same Delete and the per-type notification controls.
 *
 * The signed link in the email footer is reusable (it must work in an old
 * email), so it never deletes anything by itself. Its "Delete my data" button
 * mails a fresh one-time link to the person's address. That link works once
 * and for 30 minutes, and opening it shows a "Delete my data" button (a POST,
 * which a mail client's link prefetch cannot trigger). Only that button
 * removes the person and their assignments for good.
 */
final class ManageController
{
    public const DELETE = 'delete';

    /** The one-time link that deletes: its auth token purpose and lifetime. */
    public const DELETE_PURPOSE = 'manage_delete';
    public const DELETE_TTL = 30 * MINUTE_IN_SECONDS;

    /** The query parameter that carries the one-time delete link's token. */
    public const DELETE_PARAM = 'delete';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly PersonEraser $eraser,
        private readonly AuthTokenRepository $tokens,
        private readonly Mailer $mailer
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
        $page = $this->respond($request);

        status_header($page['status']);
        header('Content-Type: text/html; charset=utf-8');
        // This page shows a person's email address and its URL carries a token
        // that leads to deleting their account, so it has no business in a
        // shared cache or a search index. The ticket page already refuses to be
        // stored; this is the same reasoning applied to the more dangerous of
        // the two.
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and esc_html()'d values in the page builders.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * The page for a request, without sending it.
     *
     * With the one-time delete link: GET shows the "Delete my data" button,
     * and its POST spends the link and deletes. With the reusable signed
     * link: GET shows the account page, and its POST mails the one-time link.
     *
     * @return array{status: int, html: string}
     */
    public function respond(WP_REST_Request $request): array
    {
        $isPost = 'POST' === $request->get_method();
        $deleteToken = (string) $request->get_param(self::DELETE_PARAM);

        if ('' !== $deleteToken) {
            if (! $isPost) {
                return ['status' => 200, 'html' => $this->confirmDeletePage($deleteToken)];
            }

            $personId = $this->tokens->consumeOnce($deleteToken, self::DELETE_PURPOSE);
            $person = null === $personId ? null : $this->people->find($personId);

            if (null === $person) {
                return $this->expired();
            }

            $outcome = $this->apply($person->id, (string) $request->get_param('action'));

            return [
                'status' => 200,
                'html' => Language::forPerson($person, fn (): string => $this->confirmationPage($outcome)),
            ];
        }

        $personId = SignedLink::verify('manage', (string) $request->get_param('token'));
        $person = null === $personId ? null : $this->people->find($personId);

        if (null === $person) {
            return $this->expired();
        }

        if ($isPost && self::DELETE === (string) $request->get_param('action')) {
            return [
                'status' => 200,
                'html' => Language::forPerson($person, fn (): string => $this->sendDeleteLink($person)),
            ];
        }

        $token = (string) $request->get_param('token');

        return [
            'status' => 200,
            'html' => Language::forPerson($person, fn (): string => $this->accountPage($person, $token)),
        ];
    }

    /**
     * Performs a manage action and reports which one happened.
     *
     * @return self::DELETE|''
     */
    public function apply(int $personId, string $action): string
    {
        if (self::DELETE === $action) {
            $this->eraser->erase($personId);

            return self::DELETE;
        }

        return '';
    }

    /**
     * @return array{status: int, html: string}
     */
    private function expired(): array
    {
        return [
            'status' => 404,
            'html' => $this->document(
                __('Link expired', 'eventcrew'),
                '<p>' . esc_html__('This link is no longer valid.', 'eventcrew') . '</p>'
            ),
        ];
    }

    /**
     * Mails the one-time delete link to the person's own address and answers
     * the page that says so.
     */
    private function sendDeleteLink(Person $person): string
    {
        $raw = $this->tokens->issue($person->id, self::DELETE_PURPOSE, self::DELETE_TTL);
        $url = add_query_arg([self::DELETE_PARAM => $raw], rest_url('eventcrew/v1/manage'));

        $sent = $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('delete_data_confirm', ['person' => $person]),
            EmailBody::withGreeting(
                $person->displayName,
                MessageTemplates::body('delete_data_confirm', ['person' => $person])
            ),
            [['label' => __('Delete my data', 'eventcrew'), 'url' => $url]]
        );

        if ($sent) {
            /* translators: %s: the person's email address */
            $message = __('We sent a link to %s. Open it within 30 minutes to delete your data.', 'eventcrew');
            $message = sprintf($message, $person->email);
        } else {
            $message = __('The email could not be sent. Try again later.', 'eventcrew');
        }

        return $this->document(__('Manage your EventCrew account', 'eventcrew'), '<p>' . esc_html($message) . '</p>');
    }

    private function accountPage(Person $person, string $token): string
    {
        $body = '<p>' . esc_html($person->email) . '</p>'
            . '<p>' . esc_html__('Deleting erases your account and history for good. This cannot be undone.', 'eventcrew') . '</p>'
            . $this->form('token', $token, __('Delete my data', 'eventcrew'));

        return $this->document(__('Manage your EventCrew account', 'eventcrew'), $body);
    }

    /**
     * The page the one-time delete link opens: the warning and the button
     * that deletes. The link is checked and spent only when the button is
     * pressed.
     */
    private function confirmDeletePage(string $deleteToken): string
    {
        $warning = __('Deleting erases your account and history for good. This cannot be undone.', 'eventcrew');
        $body = '<p>' . esc_html($warning) . '</p>'
            . $this->form(self::DELETE_PARAM, $deleteToken, __('Delete my data', 'eventcrew'));

        return $this->document(__('Manage your EventCrew account', 'eventcrew'), $body);
    }

    /** A red "delete" button that POSTs $value as the $field parameter. */
    private function form(string $field, string $value, string $label): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="%s" value="%s"><input type="hidden" name="action" value="%s"><button type="submit" style="padding:.6em 1em;color:#b32d2e">%s</button></form>',
            esc_attr($field),
            esc_attr($value),
            esc_attr(self::DELETE),
            esc_html($label)
        );
    }

    private function confirmationPage(string $outcome): string
    {
        $message = match ($outcome) {
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
