<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BotLog;
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

    /** The page that carries the ticket buyer privacy notice. */
    public const BUYER_PAGE_OPTION = 'eventcrew_privacy_buyer_page_id';

    /**
     * How many days after the event a ticket buyer's email address, name and
     * ticket code are deleted, unless they joined the newsletter.
     * RetentionPurge enforces it and the
     * ticket buyer notice states it, both from this one number.
     */
    public const BUYER_EMAIL_DAYS = 60;

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
     *
     * Kept to one short clause. It shares a narrow, centred column with the
     * sign-in hint, where a sentence long enough to wrap breaks into ragged
     * halves and reads as a paragraph nobody asked for - and the detail it was
     * trying to summarise is one tap away in the notice itself, which is the
     * whole point of linking to it. Short enough to survive that column, true
     * enough to stand alone.
     */
    public static function noticeLine(): string
    {
        return __('We store your email to run the crew.', 'eventcrew');
    }

    /** The link text that follows it. */
    public static function noticeLinkLabel(): string
    {
        return __('Privacy', 'eventcrew');
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

    public static function buyerPageId(): int
    {
        return (int) get_option(self::BUYER_PAGE_OPTION, 0);
    }

    /**
     * Where the ticket buyer notice is: its own page if one is chosen and
     * published, otherwise WordPress's own privacy policy page (which carries
     * the same text as a suggestion), otherwise ''.
     */
    public static function buyerNoticeUrl(): string
    {
        foreach ([self::buyerPageId(), (int) get_option('wp_page_for_privacy_policy', 0)] as $pageId) {
            if ($pageId > 0 && 'publish' === get_post_status($pageId)) {
                $url = get_permalink($pageId);

                if (is_string($url) && '' !== $url) {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * The public privacy notice for people who bought a ticket in the web
     * shop. It is separate from the crew notice because ticket buyers are not
     * crew members: they never signed up here, and their data comes from the
     * shop's order export. Same shape as sections(), and shown only when
     * isConfigured() is true, for the same reason.
     *
     * @return array<int, array{heading: string, paragraphs: array<int, string>}>
     */
    public static function buyerSections(): array
    {
        return [
            [
                'heading' => __('Who is responsible for your data', 'eventcrew'),
                'paragraphs' => self::buyerControllerParagraphs(),
            ],
            [
                'heading' => __('What we hold about you', 'eventcrew'),
                'paragraphs' => self::buyerHeldParagraphs(),
            ],
            [
                'heading' => __('Why we hold it', 'eventcrew'),
                'paragraphs' => self::buyerPurposeParagraphs(),
            ],
            [
                'heading' => __('Who else can see it', 'eventcrew'),
                'paragraphs' => self::buyerRecipientParagraphs(),
            ],
            [
                'heading' => __('How long we keep it', 'eventcrew'),
                'paragraphs' => self::buyerRetentionParagraphs(),
            ],
            [
                'heading' => __('Your rights', 'eventcrew'),
                'paragraphs' => self::buyerRightsParagraphs(),
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
            $paragraphs[] = sprintf(
                /* translators: %d: number of days */
                __(
                    'When you use the bot, we keep a short log of which command or button you used: when, your crew record (or, if your Telegram account is not linked, its numeric identifier), whether it was a private or a group chat, the name of the command, a date if the command named one, and whether it worked. The log never contains what you wrote. The organizers use it to see how the bot is used and to find faults. Each entry is deleted after %d days.',
                    'eventcrew'
                ),
                BotLog::KEEP_DAYS
            );
        }

        if (TelegramLogin::isAvailable()) {
            $paragraphs[] = __(
                'If you sign in with Telegram, the Crew app makes a one-time code and puts a cookie in your browser that ties the code to that browser. The bot uses your Telegram account number to find your crew account. The code and the cookie expire after 30 minutes and cannot be used twice.',
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
        $paragraphs = [
            __(
                'We hold it to run the crew: to let you claim a task, to tell you about the tasks you claimed, to mark who arrived, and to work out who has earned free entry. Our legal basis is that this is necessary to do the thing you asked us to do when you signed up, and our legitimate interest in staffing our own events.',
                'eventcrew'
            ),
            __(
                'Confirmations of what you signed up for, and reminders about it, always send. They are how we deliver what you asked for, and switching them off would mean signing up for something we then never told you about.',
                'eventcrew'
            ),
            __(
                'When you take a task, or spend a free-entry credit, we also email you your ticket: a QR code and a link to your ticket page. If that task or credit is cancelled after the ticket was sent, we email you that the ticket is cancelled.',
                'eventcrew'
            ),
            self::telegramEnabled()
                ? __(
                    'Everything else is optional and switched off whenever you like — open-task alerts, standing notices, credit and ticket news, permission messages, each separately for email and for Telegram. Your notification settings are on your own Crew app and, if you use the bot, under 🔔 Notifications in /me. There is no penalty for switching any of them off.',
                    'eventcrew'
                )
                : __(
                    'Everything else is optional and switched off whenever you like — open-task alerts, standing notices, credit and ticket news, permission messages, each on its own. Your notification settings are on your own Crew app. There is no penalty for switching any of them off.',
                    'eventcrew'
                ),
        ];

        if (NewsletterCrew::enabled()) {
            $paragraphs[] = __(
                'Crew members with a verified email address get the next events announcement and can unsubscribe at any time from the link in each email.',
                'eventcrew'
            );
        }

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function recipientParagraphs(): array
    {
        $paragraphs = [
            __(
                'The organizers of our events, and the crew running the sales desk on a night, can see your name, your standing and the tasks you are on — that is the roster and the door list. Other crew members see only that a task is taken, never by whom.',
                'eventcrew'
            ),
            __(
                'We do not sell your data, we do not use it for advertising, and we do not profile you for anything beyond deciding whether you can claim a task.',
                'eventcrew'
            ),
        ];

        $processors = [];

        if (TelegramLogin::isAvailable()) {
            $processors[] = __(
                'Telegram, if you choose to link a Telegram account — messages the bot sends you pass through their service under their own privacy policy (https://telegram.org/privacy), and signing in with Telegram goes through a chat with the bot. Linking is entirely optional; the web page does everything the bot does.',
                'eventcrew'
            );
        } elseif (self::telegramEnabled()) {
            $processors[] = __(
                'Telegram, if you choose to link a Telegram account — messages the bot sends you pass through their service under their own privacy policy (https://telegram.org/privacy). Linking is entirely optional; the web page does everything the bot does.',
                'eventcrew'
            );
        }

        if (self::telegramEnabled() && AssistantSettings::isActive()) {
            $processors[] = __(
                'The server of the language model that helps the bot answer, if you write the bot a message that is not a command — the text of that message, your roles, whether you are an organizer and the conversation of the last 24 hours (in the group, only your own messages to the bot and its answers) are sent there to work out the answer. The bot keeps a private chat for 24 hours so it can follow the conversation; each message is deleted 24 hours after it was written. In the group, the bot keeps your messages to it and its answers to you for 24 hours in the same way, apart from your private chat; other messages in the group are not kept. The bot never changes anything for you without asking you to confirm. When you give feedback in a private chat, say you are unhappy or very happy, or ask for a human, the bot emails the last 24 hours of that chat, with your name and email address, to the organizers, and tells you that it did.',
                'eventcrew'
            );
        }

        if (Newsletter::enabled() && AssistantSettings::isConfigured() && TranslatedText::autoEnabled()) {
            $processors[] = __(
                'The server of the language model, for organizers who write a text for the next events announcement — the text is sent there to be translated into the other language of the announcement.',
                'eventcrew'
            );
        }

        if (Newsletter::enabled() && NewsletterWriter::enabled() && AssistantSettings::isConfigured()) {
            $processors[] = __(
                'The server of the language model, which writes the texts of the next events announcement — the events’ descriptions and their DJs’ descriptions, both public information, are sent there.',
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
            'Two exceptions. Organizers keep their record for as long as they hold the role, because it is what our events are administered through; it follows the schedule above once they no longer do. And sign-in links are not kept at all — they are deleted within the hour of being used or expiring, whichever comes first.',
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
                'Two of those you can do yourself, immediately and without asking anyone: your Crew app has a Delete button that erases your record and its history for good, and every email we send you links to it. Your notification settings on the same page stop any optional message.',
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

    /**
     * The crew notice's controller paragraphs with the first sentence changed
     * to name the ticket buyer records. The officer paragraph after it is the
     * same for both notices.
     *
     * @return array<int, string>
     */
    private static function buyerControllerParagraphs(): array
    {
        $paragraphs = self::controllerParagraphs();
        $address = self::address();

        $paragraphs[0] = '' !== $address
            ? sprintf(
                /* translators: 1: organisation name, 2: postal address, 3: contact email */
                __(
                    'The data controller for the ticket buyer records described here is %1$s, %2$s. You can reach us at %3$s.',
                    'eventcrew'
                ),
                self::controller(),
                $address,
                self::contact()
            )
            : sprintf(
                /* translators: 1: organisation name, 2: contact email */
                __(
                    'The data controller for the ticket buyer records described here is %1$s. You can reach us at %2$s.',
                    'eventcrew'
                ),
                self::controller(),
                self::contact()
            );

        return $paragraphs;
    }

    /**
     * @return array<int, string>
     */
    private static function buyerHeldParagraphs(): array
    {
        return [
            __(
                'When you buy a ticket in our web shop, we take these details from the shop\'s order export: your name, your email address, and your order or ticket code. We also store the date of the event your ticket is for. We take nothing else from your order.',
                'eventcrew'
            ),
            __(
                'The shop asks for a postal address. We do not use it. You can type a dash ("-") in the address field instead of your real address.',
                'eventcrew'
            ),
            __(
                'At the door we also note whether you came in.',
                'eventcrew'
            ),
            __(
                'If you join our next events announcement, we store your email address, whether you have confirmed it or left the announcement, the date you signed up and the date you confirmed. We also store the name you want us to greet you with, if you give one, and the language your browser prefers (Finnish or English). You can change the name and the language, or leave, with the link at the end of every announcement. When you leave, the name is deleted.',
                'eventcrew'
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function buyerPurposeParagraphs(): array
    {
        return [
            __(
                'Your name and ticket code are used to check you in at the door. The legal basis is that this is necessary to give you the entry you paid for.',
                'eventcrew'
            ),
            __(
                'When your ticket is added to our door list, we email you a thank-you message with your ticket: a QR code, your ticket code and a link to your ticket page. You show the QR code at the door. The legal basis is the same: it is necessary to give you the entry you paid for.',
                'eventcrew'
            ),
            __(
                'If an organizer puts you on a guest list with your email address, we store your name, your email address, how many people you bring and the date of the event. We email you a thank-you message with your ticket in the same way, and use the rest to check you in at the door.',
                'eventcrew'
            ),
            __(
                'If your ticket was missing from our door list and the door desk adds you with your email address, we email you your ticket in the same way.',
                'eventcrew'
            ),
            __(
                'If you are removed from the guest list after your ticket was emailed, we email you that your ticket is cancelled.',
                'eventcrew'
            ),
            __(
                'Your email address is used to send you one short message after the event: a thank-you if you came, or a sorry-we-missed-you if you did not. The same message invites you to join our next events announcement. The legal basis is our legitimate interest in keeping in touch with the people who bought a ticket.',
                'eventcrew'
            ),
            __(
                'If you are on a guest list with your email address, you get the same message after the event.',
                'eventcrew'
            ),
            __(
                'The links in that message go through our own site first. When you open one of them, we note the time you first opened a link from your message. We do not note which link it was, and we do not note anything about your device. We use this only to count how many people opened a link, so we know whether the message is useful. The links also carry a label (utm_source, utm_medium and utm_campaign) that tells our site and our web shop the visit came from this message. The legal basis is our legitimate interest in knowing whether our messages are useful. The time is kept with your ticket record and is no longer tied to you once your name and email address are deleted.',
                'eventcrew'
            ),
            __(
                'You are added to the next events announcement only if you press its button in that message. We then take the first word of the name on your ticket as the name to greet you with. Not joining has no effect on your ticket.',
                'eventcrew'
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function buyerRecipientParagraphs(): array
    {
        return [
            __(
                'The organizers of our events, and the crew working the door on the night, can see your name on the door list.',
                'eventcrew'
            ),
            __(
                'We do not sell your data and we do not use it for advertising.',
                'eventcrew'
            ),
            __(
                'The web shop where you bought your ticket holds your order under its own privacy policy. Our email is delivered by an email service, since every message we send leaves this site as an email.',
                'eventcrew'
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function buyerRetentionParagraphs(): array
    {
        return [
            sprintf(
                /* translators: %d: number of days */
                __(
                    'We delete your email address, your name and your ticket code %d days after the event, unless you have joined our next events announcement. This happens automatically.',
                    'eventcrew'
                ),
                self::BUYER_EMAIL_DAYS
            ),
            sprintf(
                /* translators: %d: number of days */
                __(
                    'A guest\'s email address is deleted %d days after the event too. The guest\'s name stays on that night\'s guest list.',
                    'eventcrew'
                ),
                self::BUYER_EMAIL_DAYS
            ),
            __(
                'If you join the next events announcement, we keep your email address for the announcement until you leave it. You can leave it at any time with the link in every announcement. When you leave, we keep your address marked as left, and send you no more announcements.',
                'eventcrew'
            ),
            sprintf(
                /* translators: %d: number of days */
                __(
                    'If you sign up for the next events announcement but do not confirm your address, the sign-up is deleted after %d days.',
                    'eventcrew'
                ),
                Newsletter::PENDING_DAYS
            ),
            __(
                'Until then, your name, your ticket code and whether you came in are on that night\'s door list. After that, the list keeps only how many tickets were sold and used.',
                'eventcrew'
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function buyerRightsParagraphs(): array
    {
        return [
            __(
                'You can ask for a copy of what we hold about you, ask us to correct it, ask us to delete it, and object to our use of it.',
                'eventcrew'
            ),
            sprintf(
                /* translators: %s: the controller's contact address */
                __('To do any of these, write to %s and we will answer within one month.', 'eventcrew'),
                self::contact()
            ),
            __(
                'If you think we have handled your data badly, you can complain to your national data protection authority. In the EU you can complain to the one where you live, work, or where the problem happened.',
                'eventcrew'
            ),
        ];
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
