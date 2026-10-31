<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * A member created at the door: someone who turns up to help and has no record
 * yet. The door crew types a name and an email; the record is made unverified,
 * and one email goes out with a sign-in link (which verifies the address) and
 * an invite to the crew's Telegram group.
 */
final class DoorNewMember
{
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const NO_NAME = 'no_name';
    public const BAD_EMAIL = 'bad_email';
    public const FAILED = 'failed';

    /**
     * How long the emailed sign-in link works. Longer than the login form's 30
     * minutes: the person reads it later, not at the door with the form open.
     */
    private const LOGIN_TTL = DAY_IN_SECONDS;

    /** Mirrors Web\SignupController::LOGIN_PURPOSE; the token is consumed there. */
    private const LOGIN_PURPOSE = 'web_login';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * Creates the member and mails them, or finds the member who already has
     * this email (no mail then: they are not new).
     *
     * @return array{code: string, person: ?Person}
     */
    public function create(string $name, string $email): array
    {
        $name = trim(sanitize_text_field($name));
        $email = sanitize_email($email);

        if ('' === $name) {
            return ['code' => self::NO_NAME, 'person' => null];
        }

        if ('' === $email || ! is_email($email)) {
            return ['code' => self::BAD_EMAIL, 'person' => null];
        }

        $existing = $this->people->findByEmail($email);

        if (null !== $existing) {
            return ['code' => self::EXISTS, 'person' => $existing];
        }

        $personId = $this->people->create(['email' => $email, 'display_name' => $name]);
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person) {
            return ['code' => self::FAILED, 'person' => null];
        }

        $this->welcome($person, $email);

        return ['code' => self::CREATED, 'person' => $person];
    }

    /**
     * A plain sentence for a create() answer.
     */
    public function text(string $code): string
    {
        return match ($code) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::CREATED => __('Member added. They got an email to confirm their address and join the Telegram group.', 'eventcrew'),
            self::EXISTS => __('This email already belongs to a member. Carry on with them.', 'eventcrew'),
            self::NO_NAME => __('Give their name.', 'eventcrew'),
            self::BAD_EMAIL => __('That email address does not look right.', 'eventcrew'),
            default => __('The member could not be added. Try again.', 'eventcrew'),
        };
    }

    /**
     * In the new member's own language - English, as they have not chosen one
     * yet - not in the language of the desk staff who added them.
     */
    private function welcome(Person $person, string $email): void
    {
        Language::forPerson($person, fn () => $this->sendWelcome($person, $email));
    }

    private function sendWelcome(Person $person, string $email): void
    {
        $actions = [['label' => __('Confirm my email', 'eventcrew'), 'url' => $this->loginUrl($person->id)]];
        $body = __(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            "You were added to the crew at the door. Welcome!\n\nThe first button confirms your email address and signs you in. It works once, for 24 hours.",
            'eventcrew'
        );

        $invite = $this->groupInvite();

        if ('' !== $invite) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $body .= "\n\n" . __('The second button opens the crew’s Telegram group, where tasks are posted.', 'eventcrew');
            $actions[] = ['label' => __('Join the Telegram group', 'eventcrew'), 'url' => $invite];
        }

        $body .= "\n\n" . PrivacyPolicy::noticeLine();
        $noticeUrl = PrivacyPolicy::noticeUrl();

        if ('' !== $noticeUrl) {
            $actions[] = ['label' => PrivacyPolicy::noticeLinkLabel(), 'url' => $noticeUrl];
        }

        $this->mailer->toPerson($person->id, $email, __('Welcome to the crew', 'eventcrew'), $body, $actions);
    }

    /**
     * The member's own sign-in link: the signup page when there is one, else
     * the bare admin-ajax login, which lands on the home page.
     */
    private function loginUrl(int $personId): string
    {
        $token = $this->tokens->issue($personId, self::LOGIN_PURPOSE, self::LOGIN_TTL);
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $pageUrl = $pageId > 0 ? get_permalink($pageId) : false;

        if (is_string($pageUrl) && '' !== $pageUrl) {
            return add_query_arg(['eventcrew_login' => $token], $pageUrl);
        }

        return add_query_arg(['action' => 'eventcrew_web_login', 'token' => $token], admin_url('admin-ajax.php'));
    }

    /**
     * A one-person invite link to the crew's group, so a forwarded email
     * cannot bring in others. Falls back to the group's saved link when the bot
     * cannot make one (not an admin of the group, or no group set).
     */
    private function groupInvite(): string
    {
        $board = get_option(BoardService::BOARD_OPTION, []);
        $chatId = is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0;

        if (0 !== $chatId && $this->telegram->isConfigured()) {
            $created = $this->telegram->createChatInviteLink(
                $chatId,
                /* translators: the name shown against the invite link in Telegram's admin list */
                __('EventCrew door', 'eventcrew'),
                1
            );
            $link = is_array($created) ? trim((string) ($created['invite_link'] ?? '')) : '';

            if ('' !== $link) {
                return $link;
            }
        }

        return BoardService::groupLink();
    }
}
