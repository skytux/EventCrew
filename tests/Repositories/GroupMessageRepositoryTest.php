<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\GroupMessageRepository;
use EventCrew\Tests\TestCase;

final class GroupMessageRepositoryTest extends TestCase
{
    public function testAMessageIsAddedOnceWithNullForAnUnknownSenderAndNoReply(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        $id = (new GroupMessageRepository())->add([
            'chat_id' => -100,
            'message_id' => 5,
            'person_id' => 0,
            'sender_name' => 'Kim',
            'text' => "It's on",
            'created_at' => '2026-07-20 12:00:00',
        ]);

        self::assertGreaterThan(0, $id);
        $sql = $this->wpdb->lastQuery();
        self::assertStringStartsWith(
            'INSERT IGNORE INTO wp_eventcrew_group_messages (chat_id, message_id, sender_name',
            $sql
        );
        self::assertStringNotContainsString('person_id', $sql);
        self::assertStringNotContainsString('reply_to', $sql);
        self::assertStringContainsString("-100, 5, 'Kim', 'It\\'s on'", $sql);
    }

    public function testATwiceSentUpdateStoresNothingNew(): void
    {
        $this->wpdb->nextQueryResults[] = 0;

        self::assertSame(0, (new GroupMessageRepository())->add(['chat_id' => -100, 'message_id' => 5]));
        self::assertSame(0, (new GroupMessageRepository())->add(['chat_id' => -100, 'message_id' => 0]));
        self::assertCount(1, $this->wpdb->queries);
    }

    public function testTheRecentMessagesReadOldestFirstWithoutTheHidden(): void
    {
        $this->wpdb->nextResults[] = [['id' => 9], ['id' => 8]];

        self::assertSame([['id' => 8], ['id' => 9]], (new GroupMessageRepository())->recent(-100, 100));
        self::assertStringContainsString(
            'WHERE chat_id = -100 AND hidden = 0 ORDER BY id DESC LIMIT 100',
            $this->wpdb->lastQuery()
        );
    }
}
