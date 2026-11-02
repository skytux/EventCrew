<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Support\Language;
use EventCrew\Support\Roles;
use EventCrew\Tests\Support\SalesDeskTestCase;

/**
 * /sales: a fresh sales desk link by DM, for organizers at any time and for
 * the crew on roles that open the sales desk (Welcome by default) from 10
 * minutes before their task once they are marked arrived. The clock reads 12:00 on the night.
 */
final class SalesLinkServiceTest extends SalesDeskTestCase
{
    private const GROUP = -100;

    /**
     * Queues the asker (person $id) and the list of nights.
     */
    private function queueAsker(int $id, bool $organizer = false): void
    {
        $this->wpdb->nextRows[] = $this->personRow($id, $organizer);
        $this->wpdb->nextCols[] = [self::NIGHT, '2026-07-27'];
    }

    /**
     * Queues the reads for person 8 on door task 6: the gate's task list and
     * assignment, and - when the gate lets them through - the task list again
     * for the link's lifetime.
     */
    private function queueDoorCrew(string $role, string $start, string $status, string $end = '23:00'): void
    {
        $tasks = [$this->taskRow(6, $role, $start, '14:00'), $this->taskRow(7, 'clean', $end)];

        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, $status);
        $this->wpdb->nextResults[] = $tasks;
    }

    /**
     * @return array<int, array<string, mixed>> every sendMessage body, in order
     */
    private function messages(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['body'],
            array_filter($this->telegramCalls, static fn (array $call): bool => 'sendMessage' === $call['method'])
        ));
    }

    private function assertLinkDmTo(int $userId, array $message): void
    {
        self::assertSame($userId, $message['chat_id']);
        self::assertStringContainsString(
            'action=eventcrew_door',
            $message['reply_markup']['inline_keyboard'][0][0]['url']
        );
    }

    public function testAnOrganizerGetsALinkInTheirDmAndANoteInTheGroup(): void
    {
        $this->queueAsker(1, true);

        $this->salesLinkService()->onSalesCommand(501, self::GROUP, false);

        $messages = $this->messages();
        self::assertCount(2, $messages);
        $this->assertLinkDmTo(501, $messages[0]);
        self::assertSame(['chat_id' => self::GROUP, 'text' => '📬 Sent you a DM.'], array_intersect_key(
            $messages[1],
            ['chat_id' => 0, 'text' => '']
        ));
        // The link is issued as a one-time door token.
        self::assertSame('door', $this->wpdb->inserts[0]['data']['purpose']);
    }

    public function testInADmThereIsNoExtraNote(): void
    {
        $this->queueAsker(1, true);

        $this->salesLinkService()->onSalesCommand(501, 501, true);

        self::assertCount(1, $this->messages());
    }

    public function testArrivedWelcomeCrewGetALink(): void
    {
        $this->queueAsker(8);
        $this->queueDoorCrew('welcome', '12:10', 'arrived');

        $this->salesLinkService()->onSalesCommand(508, 508, true);

        $this->assertLinkDmTo(508, $this->messages()[0]);
    }

    public function testCrewOnAnyRoleMarkedAsOpeningTheSalesDeskGetALink(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'welcome', 'label' => 'Welcome'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        $this->queueAsker(8);
        $this->queueDoorCrew('leader', '12:00', 'arrived');

        $this->salesLinkService()->onSalesCommand(508, 508, true);

        $this->assertLinkDmTo(508, $this->messages()[0]);
    }

    public function testWelcomeCrewAreRefusedMoreThanTenMinutesBefore(): void
    {
        $this->queueAsker(8);
        $this->queueDoorCrew('welcome', '12:11', 'arrived');

        $this->salesLinkService()->onSalesCommand(508, 508, true);

        self::assertSame(
            'The sales desk link opens 10 minutes before your task, once you are marked arrived.',
            $this->messages()[0]['text']
        );
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testWelcomeCrewNotMarkedArrivedAreRefused(): void
    {
        $this->queueAsker(8);
        $this->queueDoorCrew('welcome', '12:05', 'signed_up');

        $this->salesLinkService()->onSalesCommand(508, self::GROUP, false);

        // The refusal goes to the DM; the group only gets the breadcrumb.
        $messages = $this->messages();
        self::assertCount(2, $messages);
        self::assertSame(508, $messages[0]['chat_id']);
        self::assertStringStartsWith('The sales desk link opens 10 minutes', $messages[0]['text']);
        self::assertSame(self::GROUP, $messages[1]['chat_id']);
        self::assertSame('📬 Sent you a DM.', $messages[1]['text']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testOtherCrewAreRefused(): void
    {
        $this->queueAsker(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '12:00', '13:00')];

        $this->salesLinkService()->onSalesCommand(508, 508, true);

        self::assertSame(
            'Only organizers and tonight’s welcome crew can open it.',
            $this->messages()[0]['text']
        );
    }

    public function testAfterTheEventEndsAnOrganizerStillGetsALinkButWelcomeCrewDoNot(): void
    {
        // Clean starts at 11:30: the event ended half an hour ago.
        $this->queueAsker(8);
        $this->queueDoorCrew('welcome', '10:00', 'arrived', '11:30');
        $this->salesLinkService()->onSalesCommand(508, 508, true);

        $this->queueAsker(1, true);
        $this->salesLinkService()->onSalesCommand(501, 501, true);

        $messages = $this->messages();
        self::assertSame('The sales desk for this night has closed.', $messages[0]['text']);
        $this->assertLinkDmTo(501, $messages[1]);
    }

    public function testAStrangerInTheGroupIsRefusedThereInEnglish(): void
    {
        Language::run('fi', function (): void {
            $this->salesLinkService()->onSalesCommand(777, self::GROUP, false);
        });

        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame(self::GROUP, $messages[0]['chat_id']);
        self::assertSame('Only organizers and tonight’s welcome crew can open it.', $messages[0]['text']);
    }

    public function testTheRouterSendsSalesHere(): void
    {
        // Nobody with this Telegram account: the refusal proves the 💳 Sales
        // desk button under /door ran.
        $this->updateRouter()->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 777], 'data' => 'mn:sales',
                'message' => ['chat' => ['id' => 777, 'type' => 'private']]],
        ]);

        self::assertSame(
            'Only organizers and tonight’s welcome crew can open it.',
            $this->lastCallTo('sendMessage')['text']
        );
    }
}
