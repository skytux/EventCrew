<?php

/**
 * Messages page, Messages tab (included by messages.php): one entry per email
 * and Telegram message kind, each with its own form (subject, text,
 * placeholders, formatting help, settings added by other parts) and a "Reset
 * to built-in text" form. The Ticket entry has only the ticket email text
 * form (TicketText and its {reason} lines).
 *
 * @var list<array<string, mixed>> $entries MessagesPage::entries()
 * @var string $open The kind to show opened, '' for none.
 * @var array{text: array<string, mixed>, reasons: array<string, array<string, mixed>>} $ticket The ticket email's
 *      text and {reason} lines as stored (TicketText, TicketReasons), shown in the Ticket entry.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\MessagesPage;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;

?>
    <p class="description" style="max-width:48em">
        <?php esc_html_e('The emails and Telegram messages EventCrew sends. Write a text in any language; it is translated into the other site languages, and each translation can be edited. A word in curly brackets, like {name}, is a placeholder: it is replaced when the message is sent. Buttons and the footer are added to every email and are not part of the text.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>

    <?php foreach ($entries as $eventcrew_entry) : ?>
        <?php
        $eventcrew_id = (string) $eventcrew_entry['id'];
        $eventcrew_field = 'ec-message-' . $eventcrew_id;
        ?>
        <details id="<?php echo esc_attr($eventcrew_field); ?>" class="ec-message"
            style="margin:1em 0;padding:0.5em 1em;background:#fff;border:1px solid #c3c4c7"
            <?php echo $open === $eventcrew_id ? 'open' : ''; ?>>
            <summary style="cursor:pointer;font-size:1.1em;padding:0.3em 0">
                <strong><?php echo esc_html((string) $eventcrew_entry['label']); ?></strong>
                <?php if ($eventcrew_entry['changed'] && MessagesPage::TICKET_KIND !== $eventcrew_id) : ?>
                    <em>(<?php esc_html_e('changed', 'eventcrew'); ?>)</em>
                <?php endif; ?>
            </summary>

            <p>
                <?php echo esc_html((string) $eventcrew_entry['description']); ?>
                <a href="<?php echo esc_url(MessagesPage::previewUrl($eventcrew_id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Preview', 'eventcrew'); ?></a>
            </p>

            <?php if (MessagesPage::TICKET_KIND === $eventcrew_id) : ?>
                <?php // The text the ticket email uses now; with JavaScript it saves by admin-ajax (eventcrew-jobs.js). ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ec-ajax-save>
                    <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::TICKET_ACTION); ?>">
                    <?php wp_nonce_field(MessagesPage::TICKET_ACTION); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="eventcrew-ticket-text"><?php esc_html_e('Ticket email text', 'eventcrew'); ?></label>
                            </th>
                            <td>
                                <?php
                                \EventCrew\Support\TranslatedText::field(
                                    \EventCrew\Support\TicketText::POST_NAME,
                                    'eventcrew-ticket-text',
                                    $ticket['text'],
                                    \EventCrew\Support\TicketText::MAX_LENGTH,
                                    true,
                                    '',
                                    'email.ticket'
                                );
                                ?>
                                <p class="description">
                                    <?php esc_html_e('The text of every ticket email: a bought ticket, a guest-list ticket, a crew ticket and a free-entry ticket. The heading, the ticket note, the “Open my ticket” button, the sign-off and the QR code are added around it. A first line written in *stars* is the greeting, shown in italics on its own line. Empty, or the same as the built-in text, means the built-in text.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                                </p>
                                <p class="description"><?php esc_html_e('Placeholders:', 'eventcrew'); ?></p>
                                <ul class="description" style="list-style:disc;margin-left:2em">
                                    <li><code>{first_name}</code> <?php esc_html_e('the ticket holder’s first name. When there is no name, it is left out with the space in front of it: “Hi {first_name},” becomes “Hi,”.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></li>
                                    <li><code>{event}</code> <?php esc_html_e('the event’s name.', 'eventcrew'); ?></li>
                                    <li><code>{date}</code> <?php esc_html_e('the event’s date, like 14.11.2026.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{start_time}</code> <?php esc_html_e('the event’s start time, like 19:00. Empty when the event has none.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{doors_close}</code> <?php esc_html_e('when the doors close, like 19:30. Empty when doors close is off.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{venue}</code> <?php esc_html_e('where the event is, like “📍 Dance hall”: the event’s own venue, else the default venue, else EventMesh’s. Empty when none is known.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{reason}</code> <?php esc_html_e('why the person has a ticket: one of the four lines below, chosen by the kind of ticket.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{tasks}</code> <?php esc_html_e('a crew member’s tasks on the night, as a list. Empty for everyone else.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{plus_ones}</code> <?php esc_html_e('how many people a guest may bring, like “2 people”. Empty for everyone else.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></li>
                                    <li><code>{organization}</code> <?php esc_html_e('the organization’s name (Web page tab, else the site name).', 'eventcrew'); ?></li>
                                </ul>
                                <p class="description">
                                    <?php esc_html_e('When a placeholder other than {first_name} is empty, its whole paragraph is left out, so no empty line or half sentence is left.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                                </p>
                            </td>
                        </tr>
                        <?php foreach (\EventCrew\Support\TicketReasons::KINDS as $eventcrew_reason) : ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr('eventcrew-ticket-reason-' . $eventcrew_reason); ?>"><?php echo esc_html(\EventCrew\Support\TicketReasons::label($eventcrew_reason)); ?></label>
                                </th>
                                <td>
                                    <?php
                                    \EventCrew\Support\TranslatedText::field(
                                        \EventCrew\Support\TicketReasons::POST_NAME . '[' . $eventcrew_reason . ']',
                                        'eventcrew-ticket-reason-' . $eventcrew_reason,
                                        $ticket['reasons'][$eventcrew_reason],
                                        \EventCrew\Support\TicketReasons::MAX_LENGTH,
                                        false,
                                        '',
                                        'email.ticket_reason.' . $eventcrew_reason
                                    );
                                    ?>
                                    <p class="description">
                                        <?php esc_html_e('The {reason} line of the ticket email for this kind of ticket. Empty, or the same as the built-in line, means the built-in line.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php submit_button(__('Save the ticket email text', 'eventcrew')); ?>
                    <div class="ec-ajax-notice" data-ec-ajax-notice aria-live="polite"></div>
                </form>
            <?php else : // The Ticket entry has only the form above; every other entry its message text below. ?>
                <?php if (! $eventcrew_entry['wired']) : ?>
                    <p class="notice notice-warning inline" style="padding:0.5em">
                        <?php esc_html_e('This message does not use the text below yet: it still sends its built-in text. A text saved here is kept for when it does.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                <?php endif; ?>

                <?php // With JavaScript the page posts this form to admin-ajax and stays (eventcrew-jobs.js). ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ec-ajax-save>
                    <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::SAVE_ACTION); ?>">
                    <input type="hidden" name="kind" value="<?php echo esc_attr($eventcrew_id); ?>">
                    <?php wp_nonce_field(MessagesPage::SAVE_ACTION); ?>

                    <?php if (null !== $eventcrew_entry['subject']) : ?>
                        <h3><?php esc_html_e('Subject', 'eventcrew'); ?></h3>
                        <?php
                        TranslatedText::field(
                            MessageTemplates::POST_NAME . '[' . MessageTemplates::SUBJECT . ']',
                            $eventcrew_field . '-subject',
                            $eventcrew_entry['subject'],
                            MessageTemplates::SUBJECT_MAX_LENGTH,
                            false,
                            '',
                            'message.' . $eventcrew_id . '.' . MessageTemplates::SUBJECT
                        );
                        ?>
                    <?php endif; ?>

                    <h3>
                        <?php
                        echo MessageTemplate::CHANNEL_TELEGRAM === $eventcrew_entry['channel']
                            ? esc_html__('Telegram message', 'eventcrew')
                            : esc_html__('Email text', 'eventcrew');
                        ?>
                    </h3>
                    <?php
                    TranslatedText::field(
                        MessageTemplates::POST_NAME . '[' . MessageTemplates::BODY . ']',
                        $eventcrew_field . '-body',
                        $eventcrew_entry['body'],
                        MessageTemplates::BODY_MAX_LENGTH,
                        true,
                        '',
                        'message.' . $eventcrew_id . '.' . MessageTemplates::BODY
                    );
                    ?>
                    <p class="description"><?php echo esc_html((string) $eventcrew_entry['formatting']); ?></p>

                    <?php if ([] !== $eventcrew_entry['placeholders']) : ?>
                        <h3><?php esc_html_e('Placeholders', 'eventcrew'); ?></h3>
                        <table class="widefat striped" style="max-width:48em">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Placeholder', 'eventcrew'); ?></th>
                                    <th scope="col"><?php esc_html_e('Becomes', 'eventcrew'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventcrew_entry['placeholders'] as $eventcrew_tag => $eventcrew_what) : ?>
                                    <tr>
                                        <td>
                                            <code><?php echo esc_html((string) $eventcrew_tag); ?></code>
                                            <?php if (in_array($eventcrew_tag, $eventcrew_entry['required'], true)) : ?>
                                                <br><strong>
                                                    <?php esc_html_e('Required: a text without it is not saved.', 'eventcrew'); ?>
                                                </strong>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html((string) $eventcrew_what); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php
                    // Room for the per-template settings of other parts (the AI
                    // tick and prompt, the hybrid tick). They post inside this
                    // form and are read back through MessageTemplates::SETTINGS_FILTER.
                    do_action(MessageTemplates::FIELDS_ACTION, $eventcrew_entry['template'], $eventcrew_entry['settings']);
                    ?>

                    <?php submit_button(__('Save this message', 'eventcrew')); ?>
                    <div class="ec-ajax-notice" data-ec-ajax-notice aria-live="polite"></div>
                </form>

                <?php if ($eventcrew_entry['changed']) : ?>
                    <?php
                    $eventcrew_confirm = __(
                        'Forget the changed text of this message and use the built-in text again?',
                        'eventcrew'
                    );
                    ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        onsubmit="return confirm(<?php echo esc_attr((string) wp_json_encode($eventcrew_confirm)); ?>);">
                        <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::RESET_ACTION); ?>">
                        <input type="hidden" name="kind" value="<?php echo esc_attr($eventcrew_id); ?>">
                        <?php wp_nonce_field(MessagesPage::RESET_ACTION); ?>
                        <?php submit_button(__('Reset to built-in text', 'eventcrew'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php endif; // Not the Ticket entry. ?>
        </details>
    <?php endforeach; ?>

    <?php TranslatedText::script(); ?>
