<?php

/**
 * The message preview page (MessagesPage::preview()), a page of its own:
 * the language links, then one kind as it would be sent with sample data
 * (MessagePreview::render()), or the list of every kind with its Preview
 * link. An email is shown in a sandboxed frame, which runs no scripts.
 *
 * @var string $lang The language asked for, '' for the admin's language.
 * @var array<string, string> $languages Site language code => name.
 * @var string $kind The kind shown, '' for the list.
 * @var array<string, mixed>|null $message MessagePreview::render() of $kind.
 * @var list<array{id: string, label: string, channel: string}> $entries Every kind.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\MessagesPage;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplate;

$eventcrew_title = null === $message ? __('Message previews', 'eventcrew') : (string) $message['label'];
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
    pre { white-space: pre-wrap; word-break: break-word; background: #fff; border: 1px solid #c3c4c7; padding: 12px; }
</style>
</head>
<body>
<div class="ec-box">
    <h1><?php echo esc_html($eventcrew_title); ?></h1>
    <p class="ec-note">
        <?php esc_html_e('Sample data: a made-up person and the next event. Nothing is sent, and the links in the message lead nowhere.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>
    <p>
        <?php esc_html_e('Language:', 'eventcrew'); ?>
        <?php foreach ($languages as $eventcrew_code => $eventcrew_name) : ?>
            <?php if ($eventcrew_code === $lang) : ?>
                <strong><?php echo esc_html($eventcrew_name); ?></strong>
            <?php else : ?>
                <a href="<?php echo esc_url(MessagesPage::previewUrl($kind, (string) $eventcrew_code)); ?>"><?php echo esc_html($eventcrew_name); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (null !== $message) : ?>
            · <a href="<?php echo esc_url(MessagesPage::previewUrl('', $lang)); ?>"><?php esc_html_e('All previews', 'eventcrew'); ?></a>
        <?php endif; ?>
    </p>

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
