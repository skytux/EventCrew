<?php

/**
 * Settings → Telegram bot: the "Sign in with Telegram" row. Included by
 * settings.php inside the Telegram table, and rendered on its own by the test.
 *
 * @var array{web_login: array{enabled: bool, origin: string, client_id: string}} $telegram
 */

$eventcrew_web_login = $telegram['web_login'];
?>
<tr>
    <th scope="row"><?php esc_html_e('Sign in with Telegram', 'eventcrew'); ?></th>
    <td>
        <label>
            <input
                type="checkbox"
                name="telegram_web_login"
                value="1"
                <?php checked($eventcrew_web_login['enabled']); ?>>
            <?php esc_html_e('Allow sign-in with Telegram on the Crew app', 'eventcrew'); ?>
        </label>
        <p class="description">
            <?php
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            esc_html_e('Telegram only allows this on addresses you name. In the @BotFather chat, tap Open, then this bot, then Bot Settings, then Web Login (called Login Widget in some versions), and add this address under Allowed URLs:', 'eventcrew');
            ?>
            <?php if ('' !== $eventcrew_web_login['origin']) : ?>
                <code><?php echo esc_html($eventcrew_web_login['origin']); ?></code>
            <?php endif; ?>
        </p>
        <?php if ('' !== $eventcrew_web_login['client_id']) : ?>
            <p class="description">
                <?php esc_html_e('Client ID (the bot ID):', 'eventcrew'); ?>
                <code><?php echo esc_html($eventcrew_web_login['client_id']); ?></code>
            </p>
        <?php endif; ?>
    </td>
</tr>
