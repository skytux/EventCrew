<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\Language;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TakeoverService;
use WP_REST_Request;

/**
 * The page behind the "Answer the request" link in a takeover request email:
 * /wp-json/eventcrew/v1/takeover?token=…
 *
 * Opening the link only shows the request and two buttons, because mail
 * scanners open links and must not answer for the holder. Pressing a button
 * sends a POST, and only the POST answers.
 */
final class TakeoverController
{
    public function __construct(private readonly TakeoverService $takeovers)
    {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/takeover',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): void
    {
        $page = $this->respond(
            $request->get_method(),
            (string) $request->get_param('token'),
            (string) $request->get_param('answer')
        );

        status_header($page['status']);
        header('Content-Type: text/html; charset=utf-8');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and esc_html()'d values in page().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * What the link does and the page it answers with, in the holder's
     * language.
     *
     * @return array{status: int, html: string}
     */
    public function respond(string $method, string $token, string $answer): array
    {
        $id = SignedLink::verify(TakeoverService::PURPOSE, $token);
        $takeover = null === $id ? null : $this->takeovers->find($id);
        $holder = null === $takeover ? null : $this->takeovers->holderOf($takeover);

        if (null === $takeover || null === $holder) {
            return $this->page(404, __('This link is no longer valid.', 'eventcrew'));
        }

        return Language::forPerson($holder, function () use ($method, $token, $answer, $takeover, $holder): array {
            if ('POST' === strtoupper($method) && in_array($answer, ['yes', 'no'], true)) {
                return $this->page(200, $this->takeovers->answer($takeover->id, 'yes' === $answer, $holder->id));
            }

            if (! $takeover->isPending()) {
                return $this->page(200, TakeoverService::closedText($takeover->status));
            }

            $summary = $this->takeovers->summary($takeover);

            if ('' === $summary) {
                return $this->page(200, TakeoverService::closedText(''));
            }

            return $this->page(200, $summary, $this->buttons($token));
        });
    }

    /**
     * @return array{status: int, html: string}
     */
    private function page(int $status, string $message, string $extra = ''): array
    {
        $paragraphs = '';

        foreach (preg_split('/\n{2,}/', $message) ?: [] as $paragraph) {
            $paragraphs .= '<p>' . nl2br(esc_html($paragraph)) . '</p>';
        }

        return [
            'status' => $status,
            'html' => sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
                '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em"><h1 style="font-size:1.2em">%1$s</h1>%2$s</body></html>',
                esc_html(__('Takeover request', 'eventcrew')),
                $paragraphs . $extra
            ),
        ];
    }

    private function buttons(string $token): string
    {
        $form = static fn (string $answer, string $label): string => sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="token" value="%1$s"><input type="hidden" name="answer" value="%2$s"><button type="submit" style="padding:.6em 1em">%3$s</button></form>',
            esc_attr($token),
            esc_attr($answer),
            esc_html($label)
        );

        return $form('yes', __('✅ Let them take it', 'eventcrew'))
            . $form('no', __('❌ Keep my task', 'eventcrew'));
    }
}
