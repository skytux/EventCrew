<?php

/**
 * Settings → Telegram bot: the "Sign in with Telegram" row. Included by
 * settings.php inside the Telegram table, and rendered on its own by the test.
 *
 * @var array{web_login: array{enabled: bool, needs_username: bool}} $telegram
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
            esc_html_e('The Crew app shows a Telegram button next to Email. It opens this bot, and the bot sends a button that signs the person in. Someone without an account first gives the bot their email and confirms it. It needs the bot token above.', 'eventcrew');
            ?>
        </p>
        <?php if ($eventcrew_web_login['needs_username']) : ?>
            <p class="description">
                <strong>
                    <?php
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    esc_html_e('The button stays hidden until the bot username is filled in. Installing the webhook fills it in.', 'eventcrew');
                    ?>
                </strong>
            </p>
        <?php endif; ?>
    </td>
</tr>
