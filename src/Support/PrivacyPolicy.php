<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\TelegramClient;

/**
 * Who is answerable for the crew's personal data, and the notice that says so.
 *
 * Everything a GDPR notice has to state that only the organizer can know - the
 * controller's identity, a data protection officer where one is designated, how
 * long records are kept - lives here as options, and the prose that surrounds
 * them is assembled here too. One source, three readers: the public
 * [eventcrew_privacy] page, the suggested text WordPress folds into its own
 * privacy policy, and the one-liner under the sign-in field. They cannot drift
 * apart, because there is only one of them.
 *
 * Static like SendWindow: every method is a read of an option or a pure
 * assembly over one, so there is no state to hold and nothing to wire.
 *
 * The sections deliberately describe what this plugin *actually* does rather
 * than what a template thinks a plugin does. The Telegram and Cloudflare
 * paragraphs appear only when those integrations are switched on, because a
 * notice that lists recipients who never receive anything is not a more
 * cautious notice - it is a less accurate one.
 */
final class PrivacyPolicy
{
    public const CONTROLLER_OPTION = 'eventcrew_privacy_controller';
    public const ADDRESS_OPTION = 'eventcrew_privacy_address';
    public const CONTACT_OPTION = 'eventcrew_privacy_contact';
    public const DPO_NAME_OPTION = 'eventcrew_privacy_dpo_name';
    public const DPO_CONTACT_OPTION = 'eventcrew_privacy_dpo_contact';
    public const RETENTION_OPTION = 'eventcrew_privacy_retention_years';
    public const PURGE_OPTION = 'eventcrew_privacy_purge';
    public const PAGE_OPTION = 'eventcrew_privacy_page_id';
    public const UPDATED_OPTION = 'eventcrew_privacy_updated_at';

    public const RETENTION_DEFAULT = 3;
    public const RETENTION_MIN = 1;
    public const RETENTION_MAX = 25;

    public static function controller(): string
    {
        return trim((string) get_option(self::CONTROLLER_OPTION, ''));
    }

    public static function address(): string
    {
        return trim((string) get_option(self::ADDRESS_OPTION, ''));
    }

    public static function contact(): string
    {
        return trim((string) get_option(self::CONTACT_OPTION, ''));
    }

    public static function dpoName(): string
    {
        return trim((string) get_option(self::DPO_NAME_OPTION, ''));
    }

    public static function dpoContact(): string
    {
        return trim((string) get_option(self::DPO_CONTACT_OPTION, ''));
    }

    /**
     * A DPO counts as designated once there is somewhere to write to. A name
     * with no contact address is not a designation any data subject can use,
     * and Article 37(7) is specifically about publishing the contact details.
     */
    public static function hasDpo(): bool
    {
        return '' !== self::dpoContact();
    }

    public static function retentionYears(): int
    {
        $years = (int) get_option(self::RETENTION_OPTION, self::RETENTION_DEFAULT);

        if ($years < self::RETENTION_MIN || $years > self::RETENTION_MAX) {
            return self::RETENTION_DEFAULT;
        }

        return $years;
    }

    /**
     * Whether the retention period is actually enforced by the purge, rather
     * than merely stated. Off by default: switching it on is a decision to let
     * a scheduled job destroy contact details unattended, and that should be a
     * thing the organizer chose, not a thing an update did to them.
     */
    public static function purgeEnabled(): bool
    {
        return (bool) get_option(self::PURGE_OPTION, false);
    }

    public static function pageId(): int
    {
        return (int) get_option(self::PAGE_OPTION, 0);
    }

    /**
     * When the notice last changed, as a timestamp, or 0 if it never has.
     *
     * A privacy notice that does not say when it was last revised leaves a
     * reader unable to tell whether they are looking at the terms they were
     * shown when they signed up. Stamped by the Settings save rather than by
     * the plugin's own version, because a release that does not touch these
     * words has not changed anything the reader agreed to.
     */
    public static function updatedAt(): int
    {
        return max(0, (int) get_option(self::UPDATED_OPTION, 0));
    }

    public static function stampUpdated(): void
    {
        update_option(self::UPDATED_OPTION, time());
    }

    /**
     * Where to send someone who wants the detail: the plugin's own privacy page
     * if the organizer made one, otherwise WordPress's own privacy policy page,
     * otherwise nowhere - and "nowhere" is a real answer the callers check,
     * because a link to an empty page is worse than a sentence with no link.
     */
    public static function noticeUrl(): string
    {
        $pageId = self::pageId();

        if ($pageId > 0 && 'publish' === get_post_status($pageId)) {
            $url = get_permalink($pageId);

            if (is_string($url) && '' !== $url) {
                return $url;
            }
        }

        $corePolicy = (int) get_option('wp_page_for_privacy_policy', 0);

        if ($corePolicy > 0 && 'publish' === get_post_status($corePolicy)) {
            $url = get_permalink($corePolicy);

            if (is_string($url) && '' !== $url) {
                return $url;
            }
        }

        return '';
    }

    /**
     * The sentence that sits under the sign-in field and in the bot's welcome.
     * Deliberately short and deliberately true: an email address is the whole
     * of what is being asked for at that moment, and the rest is a link away.
     */
    public static function noticeLine(): string
    {
        return __(
            'We store your email address and your task history so we can run the crew.',
            'eventcrew'
        );
    }

    /** The link text that follows it. */
    public static function noticeLinkLabel(): string
    {
        return __('How we handle your data', 'eventcrew');
    }

    /**
     * Whether enough has been filled in for the notice to name anyone. Without
     * a controller the page would publish a privacy notice whose first and most
     * important question - who is answerable for this - is blank, so the page
     * and the suggested text both refuse rather than pretend.
     */
    public static function isConfigured(): bool
    {
        return '' !== self::controller() && '' !== self::contact();
    }

    /**
     * The notice itself, as headed sections of paragraphs. Plain strings, no
     * markup: the page template wraps them in headings and paragraphs, and the
     * privacy-policy suggestion wraps them in the markup core expects.
     *
     * @return array<int, array{heading: string, paragraphs: array<int, string>}>
     */
    public static function sections(): array
    {
        return [
            [
                'heading' => __('Who is responsible for your data', 'eventcrew'),
                'paragraphs' => self::controllerParagraphs(),
            ],
            [
                'heading' => __('What we hold about you', 'eventcrew'),
                'paragraphs' => self::heldParagraphs(),
            ],
            [
                'heading' => __('Why we hold it', 'eventcrew'),
                'paragraphs' => self::purposeParagraphs(),
            ],
            [
                'heading' => __('Who else can see it', 'eventcrew'),
                'paragraphs' => self::recipientParagraphs(),
            ],
            [
                'heading' => __('How long we keep it', 'eventcrew'),
                'paragraphs' => self::retentionParagraphs(),
            ],
            [
                'heading' => __('Your rights', 'eventcrew'),
                'paragraphs' => self::rightsParagraphs(),
            ],
        ];
    }

    /*
     * Everything below is the notice's prose, and every string in it is a
     * single gettext literal that runs past 120 characters. They are not split:
     * a translator needs the whole sentence as one unit, and breaking one into
     * concatenated fragments would take it out of the POT as a sentence and put
     * it back as rubble. The rule is switched off for the prose and back on
     * straight after it.
     */
    // phpcs:disable Generic.Files.LineLength.TooLong

    /**
     * @return array<int, string>
     */
    private static function controllerParagraphs(): array
    {
        $paragraphs = [];

        $controller = self::controller();
        $address = self::address();
        $contact = self::contact();

        if ('' !== $address) {
            $paragraphs[] = sprintf(
                /* translators: 1: organisation name, 2: postal address, 3: contact email */
                __(
                    'The data controller for the crew records described here is %1$s, %2$s. You can reach us at %3$s.',
                    'eventcrew'
                ),
                $controller,
                $address,
                $contact
            );
        } else {
            $paragraphs[] = sprintf(
                /* translators: 1: organisation name, 2: contact email */
                __(
                    'The data controller for the crew records described here is %1$s. You can reach us at %2$s.',
                    'eventcrew'
                ),
                $controller,
                $contact
            );
        }

        if (self::hasDpo()) {
            $name = self::dpoName();

            $paragraphs[] = '' !== $name
                ? sprintf(
                    /* translators: 1: data protection officer's name, 2: their contact address */
                    __(
                        'Our data protection officer is %1$s, who you can contact directly at %2$s about anything on this page.',
                        'eventcrew'
                    ),
                    $name,
                    self::dpoContact()
                )
                : sprintf(
                    /* translators: %s: the data protection officer's contact address */
                    __(
                        'Our data protection officer can be contacted directly at %s about anything on this page.',
                        'eventcrew'
                    ),
                    self::dpoContact()
                );
        } else {
            $paragraphs[] = __(
                'We have not designated a data protection officer. We are not a public authority, our work does not involve monitoring people on a large scale, and we hold no special-category data — so none of the cases that require one apply. Write to the contact address above instead.',
                'eventcrew'
            );
        }

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function heldParagraphs(): array
    {
        $paragraphs = [
            self::telegramEnabled()
                ? __(
                    'Your email address, which is how we know who you are — there is no account and no password. A display name, if you gave one or if it came from your Telegram profile.',
                    'eventcrew'
                )
                : __(
                    'Your email address, which is how we know who you are — there is no account and no password. A display name, if you gave one.',
                    'eventcrew'
                ),
            __(
                'Every task you signed up for, when you signed up, and how it turned out: completed, covered by someone else, cancelled late, or not turned up to. Free-entry credits you have earned, been given or spent, and the events they were spent on.',
                'eventcrew'
            ),
            __(
                'Which notifications you have chosen to receive and on which channel, a record of which scheduled notices we have already sent you so we do not send them twice, and any note an organizer has added to your record.',
                'eventcrew'
            ),
            __(
                'Sign-in links are stored only as a one-way hash, never as something that could be read back out of the database and used to sign in as you.',
                'eventcrew'
            ),
        ];

        if (self::telegramEnabled()) {
            $paragraphs[] = __(
                'If you link a Telegram account, we store its numeric account and chat identifiers so the bot can recognise you and message you. We do not store your Telegram username or phone number.',
                'eventcrew'
            );
        }

        $paragraphs[] = __(
            'We do not build a stored score of you. Your standing is recalculated from the outcomes above every time it is shown, so there is no hidden rating carried around in your record.',
            'eventcrew'
        );

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function purposeParagraphs(): array
    {
        return [
            __(
                'We hold it to run the crew: to let you claim a task, to tell you about the tasks you claimed, to mark who arrived, and to work out who has earned free entry. Our legal basis is that this is necessary to do the thing you asked us to do when you signed up, and our legitimate interest in staffing our own events.',
                'eventcrew'
            ),
            __(
                'Confirmations of what you signed up for, and reminders about it, always send. They are how we deliver what you asked for, and switching them off would mean signing up for something we then never told you about.',
                'eventcrew'
            ),
            self::telegramEnabled()
                ? __(
                    'Everything else is optional and switched off whenever you like — open-task alerts, standing notices, credit and ticket news, cover and permission messages, each separately for email and for Telegram. Your notification settings are on your own crew page and, if you use the bot, under /notifications. There is no penalty for switching any of them off.',
                    'eventcrew'
                )
                : __(
                    'Everything else is optional and switched off whenever you like — open-task alerts, standing notices, credit and ticket news, cover and permission messages, each on its own. Your notification settings are on your own crew page. There is no penalty for switching any of them off.',
                    'eventcrew'
                ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function recipientParagraphs(): array
    {
        $paragraphs = [
            __(
                'The organizers of our events, and the crew leader running a night, can see your name, your standing and the tasks you are on — that is the roster and the door list. Other crew members see only that a task is taken, never by whom.',
                'eventcrew'
            ),
            __(
                'We do not sell your data, we do not use it for advertising, and we do not profile you for anything beyond deciding whether you can claim a task.',
                'eventcrew'
            ),
        ];

        $processors = [];

        if (self::telegramEnabled()) {
            $processors[] = __(
                'Telegram, if you choose to link a Telegram account — messages the bot sends you pass through their service under their own privacy policy (https://telegram.org/privacy). Linking is entirely optional; the web page does everything the bot does.',
                'eventcrew'
            );
        }

        if (self::turnstileEnabled()) {
            $processors[] = __(
                'Cloudflare, whose Turnstile check guards the sign-in form against bots (https://www.cloudflare.com/privacypolicy/). It sees the request, not your crew record.',
                'eventcrew'
            );
        }

        $processors[] = __(
            'Whoever delivers our email, since every notice we send you is an email leaving this site.',
            'eventcrew'
        );

        $paragraphs[] = __('Beyond that, the only others involved are:', 'eventcrew');

        foreach ($processors as $processor) {
            $paragraphs[] = $processor;
        }

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function retentionParagraphs(): array
    {
        $years = self::retentionYears();

        $paragraphs = [
            sprintf(
                /* translators: %d: number of years */
                _n(
                    'We keep your record for %d year after your last activity — the last task you were on, credit you were given, or credit you spent.',
                    'We keep your record for %d years after your last activity — the last task you were on, credit you were given, or credit you spent.',
                    $years,
                    'eventcrew'
                ),
                $years
            ),
        ];

        if (self::purgeEnabled()) {
            $paragraphs[] = __(
                'After that it is anonymised automatically, without anyone having to remember to do it: your email address, name, notes and any Telegram link are erased for good. What remains is an attendance record with nobody attached to it, which is no longer information about you.',
                'eventcrew'
            );
        } else {
            $paragraphs[] = __(
                'Records past that age are reviewed and cleared by hand. You do not have to wait for that — you can erase yours yourself at any time, as below.',
                'eventcrew'
            );
        }

        $paragraphs[] = __(
            'Two exceptions. Organizers and crew leaders keep their record for as long as they hold the role, because it is what our events are administered through; it follows the schedule above once they no longer do. And sign-in links are not kept at all — they are deleted within the hour of being used or expiring, whichever comes first.',
            'eventcrew'
        );

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function rightsParagraphs(): array
    {
        $paragraphs = [
            __(
                'You can ask for a copy of everything we hold about you, ask us to correct it, ask us to delete it, ask us to restrict or stop a particular use of it, and object to us holding it at all. You can also take it elsewhere in a machine-readable form.',
                'eventcrew'
            ),
            __(
                'Two of those you can do yourself, immediately and without asking anyone: your crew page has a Delete button that erases your record and its history for good, and every email we send you links to it. Your notification settings on the same page stop any optional message.',
                'eventcrew'
            ),
            sprintf(
                /* translators: %s: the controller's contact address */
                __('For anything else, write to %s and we will answer within one month.', 'eventcrew'),
                self::contact()
            ),
            __(
                'If you think we have handled your data badly, you can complain to your national data protection authority. In the EU you can complain to the one where you live, work, or where the problem happened.',
                'eventcrew'
            ),
        ];

        return $paragraphs;
    }

    // phpcs:enable Generic.Files.LineLength.TooLong

    private static function telegramEnabled(): bool
    {
        return '' !== TelegramClient::token();
    }

    private static function turnstileEnabled(): bool
    {
        return '' !== trim((string) get_option(Turnstile::SITE_KEY_OPTION, ''))
            && '' !== trim((string) get_option(Turnstile::SECRET_KEY_OPTION, ''));
    }
}
