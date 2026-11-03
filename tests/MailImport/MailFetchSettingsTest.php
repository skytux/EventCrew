<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use Brain\Monkey\Functions;
use EventCrew\MailImport\MailFetchSettings;
use EventCrew\Support\SecretBox;
use EventCrew\Tests\TestCase;

/**
 * The mailbox password is stored encrypted, a plain one from before still
 * works and is encrypted on the next read, and one that cannot be decrypted
 * reads as no password.
 */
final class MailFetchSettingsTest extends TestCase
{
    private const PASSWORD = 'mailbox-Secret-42';

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function settings(array $extra = []): array
    {
        return $extra + [
            'protocol' => MailFetchSettings::IMAP,
            'server' => 'imap.example.test',
            'username' => 'crew@example.test',
        ];
    }

    private function storedPassword(): string
    {
        return (string) $this->options[MailFetchSettings::OPTION]['password'];
    }

    public function testThePasswordRoundTrips(): void
    {
        MailFetchSettings::save($this->settings(['password' => self::PASSWORD]));

        self::assertSame(self::PASSWORD, MailFetchSettings::get()['password']);
        self::assertSame(MailFetchSettings::PASSWORD_OK, MailFetchSettings::passwordStatus());
        self::assertTrue(MailFetchSettings::isOn());
    }

    public function testTheStoredValueIsNotThePlainText(): void
    {
        MailFetchSettings::save($this->settings(['password' => self::PASSWORD]));

        $stored = $this->storedPassword();

        self::assertStringStartsWith(MailFetchSettings::PASSWORD_PREFIX, $stored);
        self::assertStringNotContainsString(self::PASSWORD, $stored);
        self::assertStringNotContainsString(
            self::PASSWORD,
            (string) base64_decode(substr($stored, strlen(MailFetchSettings::PASSWORD_PREFIX)), true)
        );
    }

    public function testAPlainLegacyPasswordStillWorksAndIsEncryptedOnRead(): void
    {
        $this->options[MailFetchSettings::OPTION] = $this->settings(['password' => self::PASSWORD]);

        self::assertSame(self::PASSWORD, MailFetchSettings::get()['password']);

        $stored = $this->storedPassword();
        self::assertStringStartsWith(MailFetchSettings::PASSWORD_PREFIX, $stored, 'Not encrypted.');
        self::assertStringNotContainsString(self::PASSWORD, $stored);
        self::assertSame('imap.example.test', $this->options[MailFetchSettings::OPTION]['server']);

        self::assertSame(self::PASSWORD, MailFetchSettings::get()['password']);
    }

    public function testAPasswordUnderAnotherKeyReadsAsEmptyWithoutAnException(): void
    {
        // Sealed under another key, as after the site's salts changed.
        $this->options[MailFetchSettings::OPTION] = $this->settings([
            'password' => MailFetchSettings::PASSWORD_PREFIX . SecretBox::encrypt(self::PASSWORD, '|another-key'),
        ]);

        self::assertSame('', MailFetchSettings::get()['password']);
        self::assertSame(MailFetchSettings::PASSWORD_UNREADABLE, MailFetchSettings::passwordStatus());
        self::assertFalse(MailFetchSettings::isOn());
    }

    public function testGarbageAfterThePrefixReadsAsEmpty(): void
    {
        $this->options[MailFetchSettings::OPTION] = $this->settings([
            'password' => MailFetchSettings::PASSWORD_PREFIX . 'not base64 at all!',
        ]);

        self::assertSame('', MailFetchSettings::get()['password']);
        self::assertSame(MailFetchSettings::PASSWORD_UNREADABLE, MailFetchSettings::passwordStatus());
    }

    public function testSavingWithoutAPasswordKeepsTheStoredOne(): void
    {
        MailFetchSettings::save($this->settings(['password' => self::PASSWORD]));
        $before = $this->storedPassword();

        MailFetchSettings::save($this->settings(['server' => 'mail.example.test']));

        self::assertSame($before, $this->storedPassword());
        self::assertSame('mail.example.test', MailFetchSettings::get()['server']);
        self::assertSame(self::PASSWORD, MailFetchSettings::get()['password']);
    }

    public function testNoPasswordIsStoredAsEmpty(): void
    {
        MailFetchSettings::save($this->settings(['password' => '']));

        self::assertSame('', $this->storedPassword());
        self::assertSame(MailFetchSettings::PASSWORD_NONE, MailFetchSettings::passwordStatus());
    }

    public function testTheFormDoesNotEchoThePassword(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        $field = strpos($template, 'eventcrew-mail-fetch-password');
        self::assertNotFalse($field);

        $start = (int) strrpos(substr($template, 0, $field), '<tr>');
        $end = (int) strpos($template, '</tr>', $field) + strlen('</tr>');
        $row = substr($template, $start, $end - $start);

        $file = tempnam(sys_get_temp_dir(), 'ec-row');
        file_put_contents((string) $file, $row);

        Functions\when('esc_html')->alias(
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES)
        );
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html($text);
        });

        $mail_fetch = MailFetchSettings::normalize($this->settings(['password' => self::PASSWORD]));

        ob_start();

        try {
            include (string) $file;
        } finally {
            $html = (string) ob_get_clean();
            unlink((string) $file);
        }

        self::assertStringContainsString('name="mail_fetch_password"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('Leave this empty to keep it.', $html);
        self::assertStringNotContainsString(self::PASSWORD, $html);
    }
}
