<?php

/**
 * The message preview page (MessagesPage::preview()), a page of its own:
 * the language links, then one kind as it would be sent with sample data
 * (MessagePreview::render()), or the list of every kind with its Preview
 * link. An email is shown in a sandboxed frame, which runs no scripts.
 * Above one kind: the event picker and, for a kind about a task, the task
 * picker (a GET form, as the page runs no scripts), and where the kind
 * stands with its AI text for that event, with "Write it now" (a POST
 * form, MessagesPage::PREVIEW_WRITE_ACTION) when it has none yet.
 *
 * @var string $lang The language asked for, '' for the admin's language.
 * @var array<string, string> $languages Site language code => name.
 * @var string $kind The kind shown, '' for the list.
 * @var array<string, mixed>|null $message MessagePreview::render() of $kind.
 * @var list<array{id: string, label: string, channel: string}> $entries Every kind.
 * @var list<array{id: int, title: string, date: string, past: bool}> $events The event picker.
 * @var list<\EventCrew\Models\Task> $tasks The task picker: the tasks of the event shown.
 * @var string $written The outcome of "Write it now": 'ok', 'failed' or ''.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\MessagesPage;
use EventCrew\Support\Dates;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplate;

$eventcrew_title = null === $message ? __('Message previews', 'eventcrew') : (string) $message['label'];
$eventcrew_event_id = (int) ($message['event_id'] ?? 0);
$eventcrew_task_id = (int) ($message['task_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr('' === $lang ? 'en' : $lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($eventcrew_title); ?></title>
<style>
    body { margin: 0; padding: 16px 24px 48px; background: #f0f0f1; color: #1d2327;
        font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    a { color: #2271b1; }
    .ec-box { max-width: 760px; margin: 0 auto; }
    .ec-meta { background: #fff; border: 1px solid #c3c4c7; padding: 8px 16px; margin: 12px 0; }
    .ec-meta dt { font-weight: 600; }
    .ec-meta dd { margin: 0 0 6px; word-break: break-word; }
    .ec-frame { width: 100%; height: 80vh; border: 1px solid #c3c4c7; background: #fff; }
    .ec-chat { background: #dfe7ee; padding: 16px; border: 1px solid #c3c4c7; }
    .ec-bubble { background: #fff; border-radius: 12px; padding: 10px 14px; max-width: 520px;
        white-space: normal; word-break: break-word; }
    .ec-buttons { list-style: none; margin: 6px 0 0; padding: 0; max-width: 548px; }
    .ec-buttons li { background: rgba(255, 255, 255, .7); border-radius: 8px; padding: 6px 10px;
        margin: 4px 0; text-align: center; }
    .ec-list li { margin: 4px 0; }
    .ec-note { color: #50575e; }
    .ec-pick { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: flex-end; margin: 12px 0; }
    .ec-pick label { display: flex; flex-direction: column; gap: 2px; }
    .ec-pick select { max-width: 100%; }
    pre { white-space: pre-wrap; word-break: break-word; background: #fff; border: 1px solid #c3c4c7; padding: 12px; }
</style>
</head>
<body>
<div class="ec-box">
    <h1><?php echo esc_html($eventcrew_title); ?></h1>
    <p class="ec-note">
        <?php esc_html_e('Sample data: a made-up person and the event chosen below, with that event’s AI texts and changes. Nothing is sent, and the links in the message lead nowhere.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>
    <p>
        <?php esc_html_e('Language:', 'eventcrew'); ?>
        <?php foreach ($languages as $eventcrew_code => $eventcrew_name) : ?>
            <?php if ($eventcrew_code === $lang) : ?>
                <strong><?php echo esc_html($eventcrew_name); ?></strong>
            <?php else : ?>
                <a href="<?php echo esc_url(MessagesPage::previewUrl($kind, (string) $eventcrew_code, $eventcrew_event_id, $eventcrew_task_id)); ?>"><?php echo esc_html($eventcrew_name); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (null !== $message) : ?>
            · <a href="<?php echo esc_url(MessagesPage::previewUrl('', $lang)); ?>"><?php esc_html_e('All previews', 'eventcrew'); ?></a>
        <?php endif; ?>
    </p>

    <?php if (null !== $message && [] !== $events) : ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ec-pick">
            <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::PREVIEW_ACTION); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(MessagesPage::PREVIEW_ACTION)); ?>">
            <input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
            <?php if ('' !== $lang) : ?>
                <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
            <?php endif; ?>
            <label>
                <?php esc_html_e('Event', 'eventcrew'); ?>
                <select name="event_id">
                    <?php foreach ($events as $eventcrew_event) : ?>
                        <option value="<?php echo esc_attr((string) $eventcrew_event['id']); ?>" <?php selected($eventcrew_event['id'], $eventcrew_event_id); ?>>
                            <?php
                            echo esc_html(Dates::dayLabel($eventcrew_event['date']) . ' · ' . $eventcrew_event['title']
                                . ($eventcrew_event['past'] ? ' (' . __('past', 'eventcrew') . ')' : ''));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ([] !== $tasks) : ?>
                <label>
                    <?php esc_html_e('Task', 'eventcrew'); ?>
                    <select name="task_id">
                        <?php foreach ($tasks as $eventcrew_task) : ?>
                            <option value="<?php echo esc_attr((string) $eventcrew_task->id); ?>" <?php selected($eventcrew_task->id, $eventcrew_task_id); ?>>
                                <?php echo esc_html(trim($eventcrew_task->roleDisplay() . ' ' . $eventcrew_task->timeRange())); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <button type="submit"><?php esc_html_e('Show', 'eventcrew'); ?></button>
        </form>
    <?php endif; ?>

    <?php if ('ok' === $written) : ?>
        <p><strong><?php esc_html_e('The AI wrote the text for this event.', 'eventcrew'); ?></strong></p>
    <?php elseif ('failed' === $written) : ?>
        <p><strong><?php esc_html_e('The AI could not write the text. Check that the AI is set up in EventCrew > Settings.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></strong></p>
    <?php endif; ?>

    <?php if (null !== $message && MessagePreview::AI_WRITTEN === $message['ai']) : ?>
        <p class="ec-note"><?php esc_html_e('This is the AI text of this message for this event, with any change made by hand, as it will be sent.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
    <?php elseif (null !== $message && MessagePreview::AI_MISSING === $message['ai']) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::PREVIEW_WRITE_ACTION); ?>">
            <?php wp_nonce_field(MessagesPage::PREVIEW_WRITE_ACTION); ?>
            <input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
            <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event_id); ?>">
            <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task_id); ?>">
            <p class="ec-note">
                <?php esc_html_e('The AI has not written this message for this event yet, so the text from the Messages page is shown.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?>
                <button type="submit"><?php esc_html_e('Write it now', 'eventcrew'); ?></button>
            </p>
        </form>
    <?php endif; ?>

    <?php if (null === $message) : ?>
        <ul class="ec-list">
            <?php foreach ($entries as $eventcrew_entry) : ?>
                <li>
                    <a href="<?php echo esc_url(MessagesPage::previewUrl($eventcrew_entry['id'], $lang)); ?>" target="_blank" rel="noopener"><?php echo esc_html($eventcrew_entry['label']); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ('' !== $message['error']) : ?>
        <p><strong><?php echo esc_html((string) $message['error']); ?></strong></p>
    <?php elseif (MessageTemplate::CHANNEL_TELEGRAM === $message['channel']) : ?>
        <div class="ec-chat">
            <div class="ec-bubble"><?php echo MessagePreview::telegramHtml((string) $message['text']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in telegramHtml(). ?></div>
            <?php if ([] !== $message['buttons']) : ?>
                <ul class="ec-buttons" aria-label="<?php esc_attr_e('Buttons', 'eventcrew'); ?>">
                    <?php foreach ($message['buttons'] as $eventcrew_button) : ?>
                        <li><?php echo esc_html((string) $eventcrew_button); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <dl class="ec-meta">
            <dt><?php esc_html_e('Subject', 'eventcrew'); ?></dt>
            <dd><?php echo esc_html((string) $message['subject']); ?></dd>
            <dt><?php esc_html_e('From', 'eventcrew'); ?></dt>
            <dd><?php echo esc_html((string) $message['from']); ?></dd>
            <dt><?php esc_html_e('To', 'eventcrew'); ?></dt>
            <dd><?php echo esc_html((string) $message['to']); ?></dd>
            <?php if ([] !== $message['headers']) : ?>
                <dt><?php esc_html_e('Headers', 'eventcrew'); ?></dt>
                <?php foreach ($message['headers'] as $eventcrew_header) : ?>
                    <dd><?php echo esc_html((string) $eventcrew_header); ?></dd>
                <?php endforeach; ?>
            <?php endif; ?>
        </dl>
        <?php if ('' !== $message['html']) : ?>
            <iframe class="ec-frame" sandbox="allow-popups allow-popups-to-escape-sandbox"
                title="<?php echo esc_attr((string) $message['subject']); ?>"
                srcdoc="<?php echo esc_attr((string) $message['html']); ?>"></iframe>
            <details>
                <summary><?php esc_html_e('Plain-text part', 'eventcrew'); ?></summary>
                <pre><?php echo esc_html((string) $message['text']); ?></pre>
            </details>
        <?php else : ?>
            <pre><?php echo esc_html((string) $message['text']); ?></pre>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
