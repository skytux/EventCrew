<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Tests\TestCase;

/**
 * The language-model connection settings: the encrypted API key, the address
 * check and the one-off save method.
 */
final class AssistantSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, bool> option name => autoload flag passed */
    private array $autoload = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->autoload = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value, mixed $autoload = null): bool {
                $this->options[$name] = $value;
                $this->autoload[$name] = false === $autoload;

                return true;
            }
        );
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('delete_transient')->justReturn(true);
    }

    public function testTheKeyRoundTripsAndIsNotStoredInPlainText(): void
    {
        AssistantSettings::saveKey('sk-secret-123');

        $stored = (string) $this->options[AssistantSettings::KEY_OPTION];

        self::assertStringNotContainsString('sk-secret-123', $stored);
        self::assertStringNotContainsString('sk-secret-123', (string) base64_decode($stored, true));
        self::assertSame('sk-secret-123', AssistantSettings::apiKey());
        self::assertSame(AssistantSettings::KEY_OK, AssistantSettings::keyStatus());
        self::assertTrue($this->autoload[AssistantSettings::KEY_OPTION], 'The key option must not be autoloaded.');
    }

    public function testTwoEncryptionsOfTheSameKeyDiffer(): void
    {
        self::assertNotSame(AssistantSettings::encrypt('same'), AssistantSettings::encrypt('same'));
    }

    public function testAKeyThatCannotBeDecryptedCountsAsNoKey(): void
    {
        AssistantSettings::saveKey('sk-secret-123');

        // One byte of the box changed - as if it had been sealed under other
        // salts: secretbox refuses it.
        $raw = (string) base64_decode((string) $this->options[AssistantSettings::KEY_OPTION], true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->options[AssistantSettings::KEY_OPTION] = base64_encode($raw);

        self::assertSame('', AssistantSettings::apiKey());
        self::assertSame(AssistantSettings::KEY_UNREADABLE, AssistantSettings::keyStatus());
    }

    public function testGarbageInTheKeyOptionCountsAsUnreadable(): void
    {
        $this->options[AssistantSettings::KEY_OPTION] = 'not base64 at all!';

        self::assertNull(AssistantSettings::decrypt('not base64 at all!'));
        self::assertSame(AssistantSettings::KEY_UNREADABLE, AssistantSettings::keyStatus());
        self::assertSame('', AssistantSettings::apiKey());
    }

    public function testNoKeyStored(): void
    {
        self::assertSame(AssistantSettings::KEY_NONE, AssistantSettings::keyStatus());
        self::assertSame('', AssistantSettings::apiKey());
    }

    public function testSaveConnectionStoresEverythingAndAnEmptyKeyKeepsTheOldOne(): void
    {
        AssistantSettings::saveConnection('https://llm.example.test/v1/chat/completions', 'model-a', 'key-1', true);

        self::assertSame('https://llm.example.test/v1', AssistantSettings::url());
        self::assertSame('model-a', AssistantSettings::model());
        self::assertSame('key-1', AssistantSettings::apiKey());
        self::assertTrue(AssistantSettings::isActive());

        AssistantSettings::saveConnection('https://llm.example.test/v2/', 'model-b');

        self::assertSame('https://llm.example.test/v2', AssistantSettings::url());
        self::assertSame('model-b', AssistantSettings::model());
        self::assertSame('key-1', AssistantSettings::apiKey(), 'An empty key keeps the stored one.');
        self::assertTrue(AssistantSettings::isEnabled(), 'A null switch leaves it as it was.');

        foreach ([AssistantSettings::URL_OPTION, AssistantSettings::MODEL_OPTION, AssistantSettings::ENABLED_OPTION] as $name) {
            self::assertTrue($this->autoload[$name], $name . ' must not be autoloaded.');
        }
    }

    public function testSaveConnectionRefusesAnAddressThatIsNotHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AssistantSettings::saveConnection('ftp://llm.example.test', 'model-a');
    }

    public function testRemoveKey(): void
    {
        AssistantSettings::saveKey('key-1');
        AssistantSettings::removeKey();

        self::assertSame(AssistantSettings::KEY_NONE, AssistantSettings::keyStatus());
    }

    public function testNotActiveWithoutAnAddressAndAModel(): void
    {
        $this->options[AssistantSettings::ENABLED_OPTION] = true;

        self::assertFalse(AssistantSettings::isActive());

        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';

        self::assertFalse(AssistantSettings::isActive());

        $this->options[AssistantSettings::MODEL_OPTION] = 'model-a';

        self::assertTrue(AssistantSettings::isActive());

        $this->options[AssistantSettings::ENABLED_OPTION] = false;

        self::assertFalse(AssistantSettings::isActive());
    }

    public function testNormalizeUrl(): void
    {
        self::assertSame('https://llm.example.test:8080/v1', AssistantSettings::normalizeUrl(' https://llm.example.test:8080/v1/ '));
        self::assertSame('http://localhost:8080/v1', AssistantSettings::normalizeUrl('http://localhost:8080/v1/chat/completions'));
        self::assertSame('http://127.0.0.1/v1', AssistantSettings::normalizeUrl('http://127.0.0.1/v1'));
        // The key and the chat text travel to it: no plain http to another machine.
        self::assertSame('', AssistantSettings::normalizeUrl('http://llm.example.test:8080/v1'));
        self::assertSame('', AssistantSettings::normalizeUrl('llm.example.test/v1'));
        self::assertSame('', AssistantSettings::normalizeUrl('javascript:alert(1)'));
    }
}
