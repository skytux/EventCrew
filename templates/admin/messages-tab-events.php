<?php

/**
 * Messages page, Coming events tab (included by messages.php): the one-time
 * texts of the next post-event email and the next announcement, then one
 * card per coming event with the texts the AI wrote for it, each with an
 * edit box and "Write again", and "Write the AI texts now" for the event.
 *
 * @var list<array{event_id: int, date: string, title: string, items: list<array{key: string, label: string,
 *     ai: array<string, string>, texts: array<string, string>, edited: array<string, bool>, failed: bool}>,
 *     doors_own: string, doors_close: string, venue_own: string, venue: string, venue_ai: bool,
 *     venue_again_url: string}> $events The doors times and the venues: the event's own, and the one in use;
 *     venue_ai when the own venue is the AI's; venue_again_url the "Read again" link, or '' when it is not shown.
 * @var bool $can_write Whether a template has the AI tick on or a placeholder exists.
 * @var array<string, mixed>|null $followup CrewAdminFollowup::view() and night (the night it is for, or '').
 * @var array<string, mixed>|null $newsletter CrewAdminNewsletter::view().
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EventAiText;
use EventCrew\Support\FollowupText;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TranslatedText;

$eventcrew_post_url = admin_url('admin-post.php');
$eventcrew_replace_edit = (string) wp_json_encode(__('This replaces your edit.', 'eventcrew'));
$eventcrew_card_style = 'margin:1em 0;padding:0.5em 1em 1em;background:#fff;border:1px solid #c3c4c7';
?>
    <div id="<?php echo esc_attr(AiPromptsPage::ONE_TIME_ANCHOR); ?>">
    <?php if (null !== $followup) : ?>
        <?php
        $eventcrew_follow_labels = [
            FollowupText::CAME => __('Thank-you text (came)', 'eventcrew'),
            FollowupText::MISSED => __('Sorry text (missed)', 'eventcrew'),
        ];
        ?>
        <div style="<?php echo esc_attr($eventcrew_card_style); ?>">
            <h2><?php esc_html_e('Post-event email', 'eventcrew'); ?></h2>
            <?php if (! $followup['enabled']) : ?>
                <p class="description"><?php esc_html_e('The post-event email is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
            <?php elseif ('' !== $followup['night']) : ?>
                <p><strong><?php echo esc_html(sprintf(
                    /* translators: %s: the night, like 14.11.2026 · Friday dance */
                    __('The next post-event email is for: %s', 'eventcrew'),
                    $followup['night']
                )); ?></strong></p>
            <?php else : ?>
                <p><?php esc_html_e('No coming night has a post-event email yet.', 'eventcrew'); ?></p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('The text between the greeting and the sign-off. You can replace it for the next email only; after that email is sent, or when that night is stopped, the text on the Messages page is used again. {event} becomes the event’s name and {date} its date.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>

            <?php foreach ($eventcrew_follow_labels as $eventcrew_follow_variant => $eventcrew_follow_label) : ?>
                <?php
                $eventcrew_follow_text = $followup['texts'][$eventcrew_follow_variant];
                $eventcrew_follow_id = 'eventcrew-followup-text-' . $eventcrew_follow_variant;
                // A saved text that is still being translated stays in the box.
                $eventcrew_follow_job = BackgroundJobs::latest(AiPromptsPage::followupTarget((string) $eventcrew_follow_variant));
                $eventcrew_follow_pending = BackgroundJobs::isActive($eventcrew_follow_job)
                    ? (string) ($eventcrew_follow_job['args']['text'] ?? '')
                    : '';
                ?>
                <h3><?php echo esc_html($eventcrew_follow_label); ?></h3>
                <?php echo BackgroundJobs::badge($eventcrew_follow_job); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
                <p class="description">
                    <?php
                    if ($eventcrew_follow_text['next']) {
                        esc_html_e('The next email uses a one-time text.', 'eventcrew');
                    } else {
                        esc_html_e('The next email uses the text on the Messages page.', 'eventcrew');
                    }
                    ?>
                </p>
                <?php foreach ($eventcrew_follow_text['texts'] as $eventcrew_lang => $eventcrew_words) : ?>
                    <p><strong><?php echo esc_html(SiteLanguages::label((string) $eventcrew_lang) . ':'); ?></strong></p>
                    <p style="white-space:pre-line;max-width:48em"><?php echo esc_html((string) $eventcrew_words); ?></p>
                <?php endforeach; ?>
                <?php if (! $eventcrew_follow_text['translated']) : ?>
                    <p class="description"><?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::FOLLOWUP_TEXT_ACTION); ?>">
                    <input type="hidden" name="variant" value="<?php echo esc_attr((string) $eventcrew_follow_variant); ?>">
                    <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                    <p>
                        <label for="<?php echo esc_attr($eventcrew_follow_id); ?>">
                            <?php esc_html_e('One-time text for the next email', 'eventcrew'); ?>
                        </label>
                        <textarea class="large-text" rows="4" name="followup_text" id="<?php echo esc_attr($eventcrew_follow_id); ?>"
                            maxlength="<?php echo (int) FollowupText::MAX_LENGTH; ?>"><?php echo esc_textarea($eventcrew_follow_pending); ?></textarea>
                        <span class="description"><?php esc_html_e('Write it in any language. It is translated into every site language when you save.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                        <?php if ($eventcrew_follow_text['next']) : ?>
                            <button type="submit" class="button-link" name="op" value="clear" style="margin-left:1em">
                                <?php esc_html_e('Reset to default', 'eventcrew'); ?>
                            </button>
                        <?php endif; ?>
                    </p>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (null !== $newsletter) : ?>
        <div style="<?php echo esc_attr($eventcrew_card_style); ?>">
            <h2><?php esc_html_e('Next events announcement', 'eventcrew'); ?></h2>
            <?php
            $eventcrew_news_job = BackgroundJobs::latest(AiPromptsPage::NEWSLETTER_TARGET);
            $eventcrew_news_pending = BackgroundJobs::isActive($eventcrew_news_job)
                ? (string) ($eventcrew_news_job['args']['text'] ?? '')
                : '';
            // While there is no organizers' text, the opening the language
            // model already wrote for the plan (CrewAdminNewsletter::view()).
            $eventcrew_news_suggested = '' === $eventcrew_news_pending ? (string) ($newsletter['suggested'] ?? '') : '';
            echo BackgroundJobs::badge($eventcrew_news_job); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
            ?>
            <?php if (! $newsletter['enabled']) : ?>
                <p class="description"><?php esc_html_e('The next events announcement is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
            <?php elseif (null !== $newsletter['plan']) : ?>
                <p><strong><?php echo esc_html(sprintf(
                    /* translators: %s: the send date, like 14.11.2026 */
                    __('Next announcement: %s, about these events:', 'eventcrew'),
                    $newsletter['plan']['send_on']
                )); ?></strong></p>
                <ul>
                    <?php foreach ($newsletter['plan']['events'] as $eventcrew_news_event) : ?>
                        <li><?php echo esc_html((string) $eventcrew_news_event); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><?php esc_html_e('No announcement is planned yet.', 'eventcrew'); ?></p>
            <?php endif; ?>
            <?php if (null !== $newsletter['text']) : ?>
                <?php foreach ($newsletter['text']['texts'] as $eventcrew_lang => $eventcrew_words) : ?>
                    <p><strong><?php echo esc_html(SiteLanguages::label((string) $eventcrew_lang) . ':'); ?></strong></p>
                    <p style="white-space:pre-line;max-width:48em"><?php echo esc_html((string) $eventcrew_words); ?></p>
                <?php endforeach; ?>
                <?php if (! $newsletter['text']['translated']) : ?>
                    <p class="description"><?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::NEWSLETTER_TEXT_ACTION); ?>">
                <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                <p>
                    <label for="eventcrew-newsletter-text"><?php esc_html_e('Text for the next announcement', 'eventcrew'); ?></label>
                    <textarea class="large-text" rows="4" name="newsletter_text" id="eventcrew-newsletter-text"
                        maxlength="<?php echo (int) NewsletterText::MAX_LENGTH; ?>"><?php echo esc_textarea('' !== $eventcrew_news_pending ? $eventcrew_news_pending : $eventcrew_news_suggested); ?></textarea>
                    <?php if ('' !== $eventcrew_news_suggested) : ?>
                        <span class="description"><?php esc_html_e('Suggested by the language model.', 'eventcrew'); ?></span><br>
                    <?php endif; ?>
                    <span class="description"><?php esc_html_e('Write it in any language. It is translated into every site language when you save, and removed after the announcement is sent.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                </p>
                <p>
                    <button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    <?php if (null !== $newsletter['text']) : ?>
                        <button type="submit" class="button-link" name="op" value="clear" style="margin-left:1em">
                            <?php esc_html_e('Reset to default', 'eventcrew'); ?>
                        </button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
    <?php endif; ?>
    </div>

    <h2><?php esc_html_e('Texts for the coming events', 'eventcrew'); ?></h2>
    <p style="max-width:48em"><?php esc_html_e('The AI writes these the day before each event. A text nobody changes is sent as the AI wrote it. A text you change is sent as you wrote it. When the AI failed, the normal message text is used.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php if ($can_write) : ?>
        <p class="description" style="max-width:48em"><?php esc_html_e('"Write the AI texts now" asks the AI at once instead of waiting for the day before. The day before, the AI then leaves these texts as they are.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php else : ?>
        <p class="description"><?php esc_html_e('No message template has the AI tick on, and there are no placeholders, so the AI writes nothing.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php endif; ?>
    <?php if ([] === $events) : ?>
        <p class="description"><?php esc_html_e('There are no coming events.', 'eventcrew'); ?></p>
    <?php endif; ?>

    <?php foreach ($events as $eventcrew_event) : ?>
        <?php
        $eventcrew_event_id = (int) $eventcrew_event['event_id'];
        $eventcrew_any_edit = false;
        $eventcrew_any_text = false;

        foreach ($eventcrew_event['items'] as $eventcrew_item) {
            $eventcrew_any_edit = $eventcrew_any_edit || in_array(true, $eventcrew_item['edited'], true);
            $eventcrew_any_text = $eventcrew_any_text || ! $eventcrew_item['failed'];
        }
        ?>
        <div id="<?php echo esc_attr(AiPromptsPage::eventAnchor($eventcrew_event_id)); ?>" style="<?php echo esc_attr($eventcrew_card_style); ?>">
            <h3><?php echo esc_html(TicketMail::shortDate((string) $eventcrew_event['date']) . ' · ' . $eventcrew_event['title']); ?></h3>

            <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::DOORS_ACTION); ?>">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event_id); ?>">
                <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                <p>
                    <label for="eventcrew-doors-<?php echo esc_attr((string) $eventcrew_event_id); ?>"><?php esc_html_e('Doors close at', 'eventcrew'); ?></label>
                    <input type="time" name="doors_close" id="eventcrew-doors-<?php echo esc_attr((string) $eventcrew_event_id); ?>"
                        value="<?php echo esc_attr((string) ($eventcrew_event['doors_own'] ?? '')); ?>">
                    <button type="submit" class="button"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    <span class="description">
                        <?php
                        if ('' !== (string) ($eventcrew_event['doors_own'] ?? '')) {
                            esc_html_e('This event’s own time. Clear it to follow the setting.', 'eventcrew');
                        } elseif ('' !== (string) ($eventcrew_event['doors_close'] ?? '')) {
                            echo esc_html(sprintf(
                                /* translators: %s: a time like 19:30 */
                                __('Blank: %s, from the setting.', 'eventcrew'),
                                (string) $eventcrew_event['doors_close']
                            ));
                        } else {
                            esc_html_e('Blank: no doors time.', 'eventcrew');
                        }
                        ?>
                    </span>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::VENUE_ACTION); ?>">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event_id); ?>">
                <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                <p>
                    <label for="eventcrew-venue-<?php echo esc_attr((string) $eventcrew_event_id); ?>"><?php esc_html_e('Venue', 'eventcrew'); ?></label>
                    <input type="text" name="venue" class="regular-text" id="eventcrew-venue-<?php echo esc_attr((string) $eventcrew_event_id); ?>"
                        maxlength="<?php echo (int) \EventCrew\Support\EventVenue::MAX_LENGTH; ?>"
                        value="<?php echo esc_attr((string) ($eventcrew_event['venue_own'] ?? '')); ?>">
                    <button type="submit" class="button"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    <span class="description">
                        <?php
                        if (! empty($eventcrew_event['venue_ai'])) {
                            esc_html_e('Suggested by the AI from the description. Change it or clear it.', 'eventcrew');
                        } elseif ('' !== (string) ($eventcrew_event['venue_own'] ?? '')) {
                            esc_html_e('This event’s own venue. Clear it to use the default venue.', 'eventcrew');
                        } elseif ('' !== (string) ($eventcrew_event['venue'] ?? '')) {
                            echo esc_html(sprintf(
                                /* translators: %s: the venue in use, like "Dance hall" */
                                __('Blank: %s.', 'eventcrew'),
                                (string) $eventcrew_event['venue']
                            ));
                        } else {
                            esc_html_e('Blank: no venue is shown.', 'eventcrew');
                        }
                        ?>
                    </span>
                    <?php if ('' !== (string) ($eventcrew_event['venue_again_url'] ?? '')) : ?>
                        <a href="<?php echo esc_url((string) $eventcrew_event['venue_again_url']); ?>"><?php esc_html_e('Read again', 'eventcrew'); ?></a>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ($can_write) : ?>
                <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>"
                    <?php if ($eventcrew_any_edit) : ?>
                        onsubmit="return confirm(<?php echo esc_attr($eventcrew_replace_edit); ?>);"
                    <?php endif; ?>>
                    <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::REWRITE_ACTION); ?>">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event_id); ?>">
                    <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                    <button type="submit" class="button">
                        <?php
                        if ($eventcrew_any_text) {
                            esc_html_e('Write them again', 'eventcrew');
                        } else {
                            esc_html_e('Write the AI texts now', 'eventcrew');
                        }
                        ?>
                    </button>
                </form>
            <?php endif; ?>

            <?php
            // Texts the AI is writing that the event has none of yet.
            $eventcrew_item_keys = array_column($eventcrew_event['items'], 'key');
            foreach (BackgroundJobs::withPrefix(EventAiText::jobPrefix($eventcrew_event_id)) as $eventcrew_new_job) {
                $eventcrew_new_key = substr((string) $eventcrew_new_job['target'], strlen(EventAiText::jobPrefix($eventcrew_event_id)));
                $eventcrew_new_badge = in_array($eventcrew_new_key, $eventcrew_item_keys, true)
                    ? ''
                    : BackgroundJobs::badge($eventcrew_new_job);

                if ('' !== $eventcrew_new_badge) {
                    printf(
                        '<p>%s: %s</p>',
                        esc_html('' === (string) $eventcrew_new_job['label'] ? $eventcrew_new_key : (string) $eventcrew_new_job['label']),
                        $eventcrew_new_badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
                    );
                }
            }
            ?>

            <?php if ([] === $eventcrew_event['items']) : ?>
                <p class="description"><?php esc_html_e('No texts are written yet for this event.', 'eventcrew'); ?></p>
            <?php endif; ?>

            <?php foreach ($eventcrew_event['items'] as $eventcrew_item) : ?>
                <?php
                $eventcrew_edited = in_array(true, $eventcrew_item['edited'], true);
                $eventcrew_again_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => AiPromptsPage::REWRITE_ACTION,
                            'event_id' => $eventcrew_event_id,
                            'key' => $eventcrew_item['key'],
                        ],
                        $eventcrew_post_url
                    ),
                    AiPromptsPage::NONCE_ACTION
                );
                ?>
                <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>" class="eventcrew-ai-text">
                    <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::EVENT_TEXT_ACTION); ?>">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event_id); ?>">
                    <input type="hidden" name="key" value="<?php echo esc_attr($eventcrew_item['key']); ?>">
                    <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                    <h4><?php echo esc_html($eventcrew_item['label']); ?></h4>
                    <?php echo BackgroundJobs::badge(BackgroundJobs::latest(EventAiText::jobTarget($eventcrew_event_id, (string) $eventcrew_item['key']))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,Generic.Files.LineLength.TooLong -- escaped in badge(). ?>
                    <?php if ($eventcrew_item['failed']) : ?>
                        <p class="description"><?php esc_html_e('The AI could not write this text. The normal message text is used, unless you write one here.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php endif; ?>
                    <?php foreach (TranslatedText::languages() as $eventcrew_lang) : ?>
                        <?php $eventcrew_id = 'eventcrew-ai-text-' . $eventcrew_event_id . '-' . sanitize_key($eventcrew_item['key']) . '-' . $eventcrew_lang; ?>
                        <p>
                            <label for="<?php echo esc_attr($eventcrew_id); ?>">
                                <?php echo esc_html(TranslatedText::label($eventcrew_lang)); ?>
                                <?php if ($eventcrew_item['edited'][$eventcrew_lang]) : ?>
                                    <em><?php esc_html_e('(changed by hand)', 'eventcrew'); ?></em>
                                <?php endif; ?>
                            </label>
                            <textarea class="large-text" rows="4" id="<?php echo esc_attr($eventcrew_id); ?>"
                                maxlength="<?php echo (int) EventAiText::MAX_TEXT; ?>"
                                name="texts[<?php echo esc_attr($eventcrew_lang); ?>]"><?php echo esc_textarea($eventcrew_item['texts'][$eventcrew_lang]); ?></textarea>
                        </p>
                    <?php endforeach; ?>
                    <p>
                        <button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                        <?php if ($eventcrew_edited && ! $eventcrew_item['failed']) : ?>
                            <button type="submit" class="button-link" name="op" value="ai" style="margin-left:1em">
                                <?php esc_html_e('Use the AI text again', 'eventcrew'); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($can_write) : ?>
                            <a href="<?php echo esc_url($eventcrew_again_url); ?>" style="margin-left:1em"
                                <?php if ($eventcrew_edited) : ?>
                                    onclick="return confirm(<?php echo esc_attr($eventcrew_replace_edit); ?>);"
                                <?php endif; ?>>
                                <?php esc_html_e('Write again', 'eventcrew'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
