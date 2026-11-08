<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SignupBoardView;
use EventCrew\Web\SignupController;

/**
 * Owner 2026-11-08: the Me tab's Tasks and Past tasks page with plain
 * Previous / Next links (no JavaScript needed), and Settings has a Telegram
 * card like the bot's web sign-in link.
 */
final class SignupPagingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('remove_query_arg')->alias(static function (array $keys, string $url): string {
            $parts = explode('?', $url, 2);
            parse_str($parts[1] ?? '', $query);

            foreach ($keys as $key) {
                unset($query[$key]);
            }

            return $parts[0] . ([] === $query ? '' : '?' . http_build_query($query));
        });
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args)
        );
    }

    public function testNextAndPreviousKeepTheOtherListsPageAndOpenTheCard(): void
    {
        $pages = [SignupBoardView::TASKS_PAGE_ARG => 1, SignupBoardView::PAST_PAGE_ARG => 1];

        self::assertSame(
            'https://site.test/crew/?lang=fi&past_page=2#eventcrew-past-tasks',
            SignupBoardView::pageUrl('https://site.test/crew/?lang=fi', $pages, SignupBoardView::PAST_PAGE_ARG, 2, 'eventcrew-past-tasks')
        );

        // On page 3 of Past tasks, Next on Tasks keeps past_page=3.
        $pages = [SignupBoardView::TASKS_PAGE_ARG => 1, SignupBoardView::PAST_PAGE_ARG => 3];
        self::assertSame(
            'https://site.test/crew/?tasks_page=2&past_page=3#eventcrew-my-tasks',
            SignupBoardView::pageUrl('https://site.test/crew/?past_page=3', $pages, SignupBoardView::TASKS_PAGE_ARG, 2, 'eventcrew-my-tasks')
        );

        // Previous to page 1 drops the argument.
        $pages = [SignupBoardView::TASKS_PAGE_ARG => 1, SignupBoardView::PAST_PAGE_ARG => 2];
        self::assertSame(
            'https://site.test/crew/#eventcrew-past-tasks',
            SignupBoardView::pageUrl('https://site.test/crew/?past_page=2', $pages, SignupBoardView::PAST_PAGE_ARG, 1, 'eventcrew-past-tasks')
        );
    }

    public function testTheTelegramCardOpensTheBotOrLinksTelegram(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => BoardService::USERNAME_OPTION === $name ? 'crew_bot' : $default
        );

        $linked = SignupController::telegramLinkView($this->person(555));
        self::assertNotNull($linked);
        self::assertTrue($linked['linked']);
        self::assertStringStartsWith('https://t.me/crew_bot?start=me', $linked['url']);

        $unlinked = SignupController::telegramLinkView($this->person(null));
        self::assertNotNull($unlinked);
        self::assertFalse($unlinked['linked']);
        self::assertStringStartsWith('https://t.me/crew_bot?start=onboard', $unlinked['url']);
    }

    public function testNoBotNoTelegramCard(): void
    {
        self::assertNull(SignupController::telegramLinkView($this->person(555)));
    }

    private function person(?int $telegram): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegram,
        ]);
    }
}
