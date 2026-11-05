<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Admin\View;
use EventCrew\Support\AiPrompts;
use EventCrew\Tests\TestCase;

/**
 * The editable prompts. The built-in texts must give exactly the instructions
 * the code had before they became editable: fixtures/ai-prompts-before.json
 * holds those old texts, copied from NewsletterWriter and BoardDjText before
 * the change. The two old translation prompts (into Finnish, into English)
 * were replaced by one prompt that translates into every site language, so
 * they are no longer in the fixture. Options live in an array.
 */
final class AiPromptsTest extends TestCase
{
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
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
    }

    public function testTheBuiltInPromptsAreTheTextsTheCodeUsedBefore(): void
    {
        $before = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ai-prompts-before.json'), true);

        self::assertIsArray($before);
        self::assertCount(5, $before);

        foreach ($before as $key => $text) {
            self::assertSame($text, AiPrompts::get($key), $key);
        }
    }

    public function testASavedPromptIsUsedAndResetBringsBackTheBuiltInText(): void
    {
        AiPrompts::save(AiPrompts::BOARD_DJ, 'Write one sentence about the DJ.');

        self::assertSame('Write one sentence about the DJ.', AiPrompts::get(AiPrompts::BOARD_DJ));
        self::assertTrue(AiPrompts::isChanged(AiPrompts::BOARD_DJ));

        AiPrompts::reset(AiPrompts::BOARD_DJ);

        self::assertSame(AiPrompts::DEFAULTS[AiPrompts::BOARD_DJ], AiPrompts::get(AiPrompts::BOARD_DJ));
        self::assertFalse(AiPrompts::isChanged(AiPrompts::BOARD_DJ));
    }

    public function testAnEmptyTextOrTheBuiltInTextIsNotKept(): void
    {
        AiPrompts::save(AiPrompts::PRACTICAL, 'Changed.');
        AiPrompts::save(AiPrompts::PRACTICAL, '   ');

        self::assertFalse(AiPrompts::isChanged(AiPrompts::PRACTICAL));

        AiPrompts::save(AiPrompts::FOLLOWUP, AiPrompts::DEFAULTS[AiPrompts::FOLLOWUP]);

        self::assertFalse(AiPrompts::isChanged(AiPrompts::FOLLOWUP));
        self::assertSame([], $this->options[AiPrompts::OPTION]);
    }

    public function testAnUnknownKeyIsIgnored(): void
    {
        AiPrompts::save('nope', 'Text.');

        self::assertArrayNotHasKey(AiPrompts::OPTION, $this->options);
        self::assertSame('', AiPrompts::get('nope'));
    }

    public function testChangedRulesReachEveryPromptThatUsesThem(): void
    {
        AiPrompts::save(AiPrompts::RULES, 'Only facts.');
        AiPrompts::save(AiPrompts::RULES_FI, 'Finnish rule.');

        self::assertStringEndsWith('Only facts. Finnish rule.', AiPrompts::get(AiPrompts::NEWSLETTER_EVENT));
        self::assertStringEndsWith('Only facts. Finnish rule.', AiPrompts::get(AiPrompts::NEWSLETTER_OPENING));
        self::assertStringEndsWith(
            'Only facts. For this email, keep the text under 300 characters.',
            AiPrompts::get(AiPrompts::FOLLOWUP)
        );
        self::assertSame('Do: Only facts.', AiPrompts::expand('Do: {rules}'));
    }

    public function testThePageSavesThePromptsAndResetsOne(): void
    {
        $page = new AiPromptsPage(new View());

        $page->applyPrompts(['prompts' => [
            AiPrompts::BOARD_DJ => 'Board text.',
            AiPrompts::PRACTICAL => AiPrompts::DEFAULTS[AiPrompts::PRACTICAL],
            'unknown' => 'Ignored.',
        ]]);

        self::assertSame([AiPrompts::BOARD_DJ => 'Board text.'], $this->options[AiPrompts::OPTION]);

        $page->applyPrompts(['reset' => AiPrompts::BOARD_DJ, 'prompts' => [AiPrompts::BOARD_DJ => 'Other.']]);

        self::assertFalse(AiPrompts::isChanged(AiPrompts::BOARD_DJ));

        $data = $page->data();
        self::assertCount(count(AiPrompts::DEFAULTS), $data['prompts']);
        self::assertSame(AiPrompts::RULES, $data['prompts'][0]['key']);
        self::assertStringContainsString('{rules}', $data['prompts'][2]['text']);
    }
}
