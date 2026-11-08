<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketTransferService;
use WP_REST_Request;

/**
 * The web pages for giving a free-entry ticket away.
 *
 * Give (/ticket/give?ticket=<credit ticket token>): the Give away link on a
 * credit ticket's page. It asks for a member's name or an email address,
 * then confirms (for an email address, with the receiver's name); a member
 * gets the ticket at once, an email address gets an offer. While an offer waits, the same page offers to cancel it. The
 * ticket's own signed link is the key, as it is for the ticket page.
 *
 * Accept (/ticket/accept?offer=<signed offer id>): the link in the offer
 * email. It shows the receiver's name as the giver typed it, to correct if
 * needed, and issues the ticket to it.
 */
final class TransferPage
{
    public const GIVE_ROUTE = '/ticket/give';

    public const ACCEPT_ROUTE = '/ticket/accept';

    /** The most members a name search offers. */
    private const MAX_MATCHES = 5;

    public function __construct(
        private readonly TicketTransferService $transfers,
        private readonly PersonRepository $people,
        private readonly TicketController $tickets
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        foreach ([self::GIVE_ROUTE => 'give', self::ACCEPT_ROUTE => 'accept'] as $route => $method) {
            register_rest_route(
                'eventcrew/v1',
                $route,
                [
                    'methods' => ['GET', 'POST'],
                    'callback' => [$this, $method],
                    'permission_callback' => '__return_true',
                ]
            );
        }
    }

    /** The Give away page of the credit ticket whose link token is $token. */
    public static function giveUrl(string $token): string
    {
        return add_query_arg(['ticket' => $token], rest_url('eventcrew/v1' . self::GIVE_ROUTE));
    }

    /** The accept page of offer $transferId: the link in the offer email. */
    public static function acceptUrl(int $transferId): string
    {
        return TicketTransferService::acceptUrl($transferId);
    }

    public function give(WP_REST_Request $request): void
    {
        self::send($this->giveHtml($request));
    }

    public function accept(WP_REST_Request $request): void
    {
        self::send($this->acceptHtml($request));
    }

    /**
     * The Give away page, in the giver's language.
     */
    public function giveHtml(WP_REST_Request $request): string
    {
        $token = (string) $request->get_param('ticket');
        $redemptionId = SignedLink::verify('credit_ticket', $token);
        $redemption = null === $redemptionId ? null : $this->transfers->redemption($redemptionId);
        $giver = null === $redemption ? null : $this->people->find($redemption->personId);

        if (null === $redemption || null === $giver) {
            return $this->tickets->plainPage(
                __('Ticket not found', 'eventcrew'),
                '<p>' . esc_html__('This ticket link is not valid.', 'eventcrew') . '</p>'
            );
        }

        return (string) Language::forPerson(
            $giver,
            fn (): string => $this->givePage($request, $token, $redemption->id, $giver)
        );
    }

    private function givePage(WP_REST_Request $request, string $token, int $redemptionId, Person $giver): string
    {
        $title = __('Give your ticket away', 'eventcrew');
        $redemption = $this->transfers->redemption($redemptionId);
        $date = (string) $redemption?->redeemedFor;
        $back = '<p class="t-small"><a href="' . esc_url(add_query_arg(['token' => $token], rest_url('eventcrew/v1/ticket'))) . '">'
            . esc_html__('Back to your ticket', 'eventcrew') . '</a></p>';
        $post = 'POST' === strtoupper((string) $request->get_method());
        $step = $post ? (string) $request->get_param('step') : '';
        $offer = $this->transfers->pendingOffer($redemptionId);

        if (null !== $offer) {
            if ('cancel' === $step) {
                $code = $this->transfers->cancelOffer($redemptionId, $giver->id);
                $text = TicketTransferService::OK === $code
                    ? __('Offer cancelled. Your ticket is yours again.', 'eventcrew')
                    : TicketTransferService::message($code);

                return $this->tickets->plainPage($title, '<p>' . esc_html($text) . '</p>' . $back);
            }

            // With the setting off the offer cannot be accepted; the giver
            // may still cancel it.
            return $this->tickets->plainPage(
                $title,
                '<p>' . esc_html(TicketTransferService::isOn() ? sprintf(
                    /* translators: %s: the name of the person the ticket was offered to */
                    __('Waiting for %s to accept', 'eventcrew'),
                    $offer->offeredTo()
                ) : TicketTransferService::message(TicketTransferService::DISABLED)) . '</p>'
                . $this->form($token, 'cancel', '', __('Cancel the offer', 'eventcrew'))
                . $back
            );
        }

        $refusal = $this->transfers->refusal($redemption, $giver->id);

        if ('' !== $refusal) {
            return $this->tickets->plainPage(
                $title,
                '<p>' . esc_html(TicketTransferService::message($refusal)) . '</p>' . $back
            );
        }

        $night = $this->transfers->nightLabel($date);

        if ('member' === $step) {
            $result = $this->transfers->giveToMember($redemptionId, $giver->id, (int) $request->get_param('person'));

            if (TicketTransferService::OK !== $result['code'] || null === $result['transfer']) {
                return $this->askPage($title, $token, TicketTransferService::message($result['code']), $back);
            }

            return $this->tickets->plainPage($title, '<p>' . esc_html(sprintf(
                /* translators: 1: the receiver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
                __('Done. %1$s has your ticket for %2$s now. Your own ticket no longer works.', 'eventcrew'),
                $this->transfers->receiverName($result['transfer']),
                $night
            )) . '</p>');
        }

        if ('email' === $step) {
            $email = sanitize_email((string) $request->get_param('email'));
            $name = (string) $request->get_param('name');
            $result = $this->transfers->offerToEmail($redemptionId, $giver->id, $email, $name);

            if (TicketTransferService::NO_THEIR_NAME === $result['code']) {
                $error = TicketTransferService::message($result['code']);

                return $this->confirmEmail($title, $token, $email, $night, $back, $error);
            }

            if (TicketTransferService::OK !== $result['code'] || null === $result['transfer']) {
                return $this->askPage($title, $token, TicketTransferService::message($result['code']), $back);
            }

            return $this->tickets->plainPage($title, '<p>' . esc_html($this->sentText($result['transfer'])) . '</p>' . $back);
        }

        if ('find' === $step) {
            return $this->findPage($title, $token, trim((string) $request->get_param('who')), $giver, $night, $back);
        }

        return $this->askPage($title, $token, '', $back);
    }

    /**
     * What the giver sees after the offer email went out, or after a
     * member's email address moved the ticket at once.
     */
    public function sentText(TicketTransfer $transfer): string
    {
        if ($transfer->isIssued()) {
            return sprintf(
                /* translators: %s: the receiver's name */
                __('Done. %s has your ticket now. Your own ticket no longer works.', 'eventcrew'),
                $this->transfers->receiverName($transfer)
            );
        }

        return sprintf(
            /* translators: %s: the name of the person the ticket was offered to */
            __('Sent. When %s accepts, the ticket moves to them. Until then your own ticket works.', 'eventcrew'),
            $transfer->offeredTo()
        );
    }

    /** The first question: a member's name, or an email address. */
    private function askPage(string $title, string $token, string $error, string $back): string
    {
        return $this->tickets->plainPage(
            $title,
            ('' === $error ? '' : '<p><strong>' . esc_html($error) . '</strong></p>')
            . '<form class="t-form" method="post" action="' . esc_url(self::giveUrl($token)) . '">'
            . '<input type="hidden" name="step" value="find">'
            . '<label>' . esc_html(self::askText()) . '<br>'
            . '<input type="text" name="who" required maxlength="190" autocomplete="off"></label>'
            . '<button type="submit">' . esc_html__('Next', 'eventcrew') . '</button></form>'
            . $back
        );
    }

    /** "Who gets your ticket? ...": the question, on the web and in the bot. */
    public static function askText(): string
    {
        return __('Who gets your ticket? Write a member’s name, or the email address of someone who is not a member.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /** The second step: the matching members, or the email address to confirm. */
    private function findPage(string $title, string $token, string $who, Person $giver, string $night, string $back): string
    {
        if (str_contains($who, '@')) {
            $email = strtolower($who);

            if (! is_email($email) || Person::isPlaceholderEmail($email)) {
                return $this->askPage($title, $token, TicketTransferService::message(TicketTransferService::BAD_EMAIL), $back);
            }

            $member = $this->people->findByEmail($email);

            if (null !== $member && $member->id === $giver->id) {
                return $this->askPage($title, $token, TicketTransferService::message(TicketTransferService::SELF), $back);
            }

            if (null !== $member) {
                return $this->confirmMember($title, $token, [$member], $night, $back);
            }

            return $this->confirmEmail($title, $token, $email, $night, $back);
        }

        $matches = self::matches($this->people, $who, $giver->id);

        if ([] === $matches) {
            return $this->askPage($title, $token, self::noMatchText(), $back);
        }

        return $this->confirmMember($title, $token, $matches, $night, $back);
    }

    /**
     * The confirm for an email address, with the receiver's name to fill in:
     * it goes in the offer email and on the ticket.
     */
    private function confirmEmail(
        string $title,
        string $token,
        string $email,
        string $night,
        string $back,
        string $error = ''
    ): string {
        return $this->tickets->plainPage(
            $title,
            '<p>' . esc_html(self::confirmEmailText($email, $night)) . '</p>'
            . ('' === $error ? '' : '<p><strong>' . esc_html($error) . '</strong></p>')
            . $this->form(
                $token,
                'email',
                '<input type="hidden" name="email" value="' . esc_attr($email) . '">'
                . '<label>' . esc_html__('Their name', 'eventcrew') . '<br>'
                . '<input type="text" name="name" required maxlength="' . (int) TicketTransferService::NAME_MAX
                . '" autocomplete="off"></label>',
                __('Send the offer', 'eventcrew')
            )
            . $back
        );
    }

    /**
     * Up to MAX_MATCHES members whose name matches $name, never the giver
     * and never an erased or banned person.
     *
     * @return list<Person>
     */
    public static function matches(PersonRepository $people, string $name, int $giverId, array $entities = []): array
    {
        $found = array_filter(
            PersonResolver::matching($people, $name, $entities),
            static fn (Person $person): bool => $person->id !== $giverId
                && ! $person->isAnonymized()
                && ! $person->isBanned()
        );

        return array_slice(array_values($found), 0, self::MAX_MATCHES);
    }

    /** "No member found by that name. ...": the miss, on the web and in the bot. */
    public static function noMatchText(): string
    {
        return __('No member found by that name. Write their name again, or an email address.', 'eventcrew');
    }

    /** The confirm question for a member. */
    public static function confirmMemberText(string $name, string $night): string
    {
        return sprintf(
            /* translators: 1: the receiver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
            __('Give your ticket for %2$s to %1$s? It moves at once and your own ticket stops working.', 'eventcrew'),
            $name,
            $night
        );
    }

    /** The confirm question for an email address. */
    public static function confirmEmailText(string $email, string $night): string
    {
        return sprintf(
            /* translators: 1: an email address, or a name and an email address, 2: the night, like "Sat 14 Nov · Dance Night" */
            __('Offer your ticket for %2$s to %1$s? They must accept before the doors open. Until then your ticket works.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $email,
            $night
        );
    }

    /**
     * @param list<Person> $members
     */
    private function confirmMember(string $title, string $token, array $members, string $night, string $back): string
    {
        $choices = '';

        foreach ($members as $i => $member) {
            $choices .= '<label><input type="radio" name="person" value="' . (int) $member->id . '"'
                . (0 === $i ? ' checked' : '') . '> ' . esc_html($member->name()) . '</label>';
        }

        $question = 1 === count($members)
            ? self::confirmMemberText($members[0]->name(), $night)
            : __('Pick the member who gets your ticket. It moves at once and your own ticket stops working.', 'eventcrew');

        if (1 === count($members)) {
            $choices = '<input type="hidden" name="person" value="' . (int) $members[0]->id . '">';
        }

        return $this->tickets->plainPage(
            $title,
            '<p>' . esc_html($question) . '</p>'
            . $this->form($token, 'member', $choices, __('Give the ticket', 'eventcrew'))
            . $back
        );
    }

    /** A form posting $step back to the give page; $fields is literal, escaped markup. */
    private function form(string $token, string $step, string $fields, string $button): string
    {
        return '<form class="t-form" method="post" action="' . esc_url(self::giveUrl($token)) . '">'
            . '<input type="hidden" name="step" value="' . esc_attr($step) . '">' . $fields
            . '<button type="submit">' . esc_html($button) . '</button></form>';
    }

    /**
     * The accept page, in the browser's language: the offer and a name
     * field; after the name, the ticket.
     */
    public function acceptHtml(WP_REST_Request $request): string
    {
        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : null;

        return (string) Language::run(Language::fromBrowser($accept), fn (): string => $this->acceptPage($request));
    }

    private function acceptPage(WP_REST_Request $request): string
    {
        $title = __('A free-entry ticket for you', 'eventcrew');
        $token = (string) $request->get_param('offer');
        $id = TicketTransferService::acceptId($token);
        $transfer = null === $id ? null : $this->transfers->find($id);

        if (null !== $transfer && $transfer->isIssued() && null === $transfer->receiverPersonId) {
            return $this->tickets->plainPage($title, $this->acceptedBody($transfer));
        }

        if (null === $transfer || null === $this->transfers->pendingOffer($transfer->redemptionId)) {
            return $this->tickets->plainPage(
                $title,
                '<p>' . esc_html(TicketTransferService::message(TicketTransferService::GONE)) . '</p>'
            );
        }

        // The setting is off: the offer cannot be accepted (a ticket already
        // accepted is shown above, as always).
        if (! TicketTransferService::isOn()) {
            return $this->tickets->plainPage(
                $title,
                '<p>' . esc_html(TicketTransferService::message(TicketTransferService::DISABLED)) . '</p>'
            );
        }

        $error = '';
        $name = $transfer->name;

        if ('POST' === strtoupper((string) $request->get_method())) {
            $name = (string) $request->get_param('name');
            $result = $this->transfers->accept($transfer->id, $name);

            if (TicketTransferService::OK === $result['code'] && null !== $result['transfer']) {
                return $this->tickets->plainPage($title, $this->acceptedBody($result['transfer']));
            }

            if (TicketTransferService::NO_NAME !== $result['code']) {
                return $this->tickets->plainPage(
                    $title,
                    '<p>' . esc_html(TicketTransferService::message($result['code'])) . '</p>'
                );
            }

            $error = TicketTransferService::message($result['code']);
        }

        $offerText = sprintf(
            /* translators: 1: the giver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
            __('%1$s is giving you their free-entry ticket for %2$s.', 'eventcrew'),
            $this->transfers->nameOf($transfer->giverPersonId),
            $this->transfers->nightLabel($transfer->eventDate)
        );

        return $this->tickets->plainPage(
            $title,
            '<p>' . esc_html($offerText) . '</p>'
            . ('' === $error ? '' : '<p><strong>' . esc_html($error) . '</strong></p>')
            . '<form class="t-form" method="post" action="' . esc_url(self::acceptUrl($transfer->id)) . '">'
            . '<label>' . esc_html__('Your name', 'eventcrew') . '<br>'
            . '<input type="text" name="name" value="' . esc_attr($name) . '" required maxlength="'
            . (int) TicketTransferService::NAME_MAX . '" autocomplete="name"></label>'
            . '<button type="submit">' . esc_html__('Accept the ticket', 'eventcrew') . '</button></form>'
        );
    }

    private function acceptedBody(TicketTransfer $transfer): string
    {
        return '<p>' . esc_html(sprintf(
            /* translators: %s: an email address */
            __('The ticket is yours. We emailed it to %s.', 'eventcrew'),
            $transfer->email
        )) . '</p><p><a href="' . esc_url(TicketMail::transferUrl($transfer->id)) . '">🎟 '
            . esc_html__('Show your ticket', 'eventcrew') . '</a></p>';
    }

    private static function send(string $html): void
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and escaped values above.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }
}
