<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorList;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\PrivacyEraser;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketTransferService;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\TicketController;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\TransferBot;
use EventCrew\Telegram\TransferPage;

/**
 * Giving a free-entry ticket away: to a member at once, to an email address
 * after it is accepted, the giver's cancel while it waits, the lapse at door
 * opening, the door's old and new codes, the labels, and the erase. Ann
 * (person 1) holds credit ticket 10 for tonight; Bob (2) and Cid (3) are
 * members. The clock reads 2026-07-20 12:00, and with no start time the
 * doors open at 17:00.
 */
final class TicketTransferTest extends SalesDeskTestCase
{
    private TransferWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new TransferWpdb();
        $this->wpdb = $this->db;
        $GLOBALS['wpdb'] = $this->db;

        $this->db->addPerson(1, 'Ann', 'ann@example.com', 501);
        $this->db->addPerson(2, 'Bob', 'bob@example.com', 502);
        $this->db->addPerson(3, 'Cid', 'cid@example.com', 503);
        $this->db->addRedemption(10, 1, self::NIGHT);

        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('is_email')->alias(
            static fn (string $email): string|false => false === filter_var($email, FILTER_VALIDATE_EMAIL) ? false : $email
        );
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
    }

    private function service(): TicketTransferService
    {
        return new TicketTransferService(
            new TicketTransferRepository(),
            new RedemptionRepository(),
            new PersonRepository(),
            new TaskRepository(),
            new CheckinRepository()
        );
    }

    private function at(string $time): void
    {
        Functions\when('current_time')->alias(
            static function (string $format) use ($time): string|int {
                $at = (int) strtotime(self::NIGHT . ' ' . $time);

                if ('mysql' === $format) {
                    return self::NIGHT . ' ' . $time;
                }

                return 'timestamp' === $format ? $at : gmdate($format, $at);
            }
        );
    }

    public function testAMemberGetsTheTicketAtOnceWithANewCodeAndTheOriginalStops(): void
    {
        $result = $this->service()->giveToMember(10, 1, 2);

        self::assertSame(TicketTransferService::OK, $result['code']);
        self::assertNotNull($result['transfer']);
        self::assertTrue($result['transfer']->isIssued());
        self::assertSame(2, $result['transfer']->receiverPersonId);
        self::assertSame(TicketTransfer::STATUS_ISSUED, $this->db->transfers[1]['status']);
        self::assertNotNull($this->db->redemptions[10]['cancelled_at']);
        self::assertSame(1, $this->db->redemptions[10]['transferred_to']);
        self::assertNotSame(TicketMail::creditCode(10), TicketMail::transferCode(1));
    }

    public function testAnOfferToAnEmailWaitsAndTheGiversTicketKeepsWorkingUntilAccepted(): void
    {
        $service = $this->service();
        $result = $service->offerToEmail(10, 1, 'Dee@Example.com', 'Dee Doe');

        self::assertSame(TicketTransferService::OK, $result['code']);
        self::assertTrue($result['transfer']->isPending());
        self::assertSame('dee@example.com', $this->db->transfers[1]['email']);
        self::assertNull($this->db->redemptions[10]['cancelled_at']);
        self::assertSame(TicketTransferService::PENDING, $service->refusal($service->redemption(10), 1));

        $accepted = $service->accept(1, '  Dee   Doe ');

        self::assertSame(TicketTransferService::OK, $accepted['code']);
        self::assertSame('issued', $this->db->transfers[1]['status']);
        self::assertSame('Dee Doe', $this->db->transfers[1]['name']);
        self::assertNull($this->db->transfers[1]['receiver_person_id']);
        self::assertSame(1, $this->db->redemptions[10]['transferred_to']);
    }

    public function testAnOfferToAnEmailNeedsTheReceiversName(): void
    {
        $result = $this->service()->offerToEmail(10, 1, 'dee@example.com', "  \t ");

        self::assertSame(TicketTransferService::NO_THEIR_NAME, $result['code']);
        self::assertSame([], $this->db->transfers);
        self::assertSame(
            'Write the name of the person who gets the ticket.',
            TicketTransferService::message(TicketTransferService::NO_THEIR_NAME)
        );
    }

    public function testTheOfferKeepsTheNameCleanedAndShort(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', '  Dee <i>Doe</i>  ' . str_repeat('x', 150));

        $name = (string) $this->db->transfers[1]['name'];
        self::assertStringStartsWith('Dee Doe x', $name);
        self::assertSame(TicketTransferService::NAME_MAX, mb_strlen($name));
    }

    public function testTheOfferEmailGreetsTheReceiverByName(): void
    {
        Functions\when('get_the_title')->justReturn('');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $mail = new TicketMail(
            new TicketBuyerRepository(),
            new GuestRepository(),
            new TaskRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );

        $named = $mail->composeTransferOffer('Ann', self::NIGHT, 'https://site.test/accept', 'Dee Doe');
        $unnamed = $mail->composeTransferOffer('Ann', self::NIGHT, 'https://site.test/accept');

        self::assertStringContainsString('Hi Dee,', $named['text']);
        self::assertStringContainsString('Hi,', $unnamed['text']);
    }

    public function testAcceptingNeedsAName(): void
    {
        $service = $this->service();
        $service->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');

        self::assertSame(TicketTransferService::NO_NAME, $service->accept(1, '   ')['code']);
        self::assertSame('pending', $this->db->transfers[1]['status']);
    }

    public function testAnEmailThatBelongsToAMemberGivesItToThatMemberAtOnce(): void
    {
        $result = $this->service()->offerToEmail(10, 1, 'bob@example.com', 'Dee Doe');

        self::assertSame(TicketTransferService::OK, $result['code']);
        self::assertTrue($result['transfer']->isIssued());
        self::assertSame(2, $result['transfer']->receiverPersonId);
    }

    public function testThePlaceholderAddressesAreRefused(): void
    {
        $result = $this->service()->offerToEmail(10, 1, 'tg123@telegram.invalid', 'Dee Doe');

        self::assertSame(TicketTransferService::BAD_EMAIL, $result['code']);
        self::assertSame([], $this->db->transfers);
    }

    public function testAGiverHasAtMostThreeOffersWaiting(): void
    {
        $service = $this->service();

        foreach ([11, 12, 13, 14] as $id) {
            $this->db->addRedemption($id, 1, '2026-07-2' . ($id - 10));
        }

        foreach ([11, 12, 13] as $i => $id) {
            self::assertSame(TicketTransferService::OK, $service->offerToEmail($id, 1, "x{$i}@example.com", 'Dee Doe')['code']);
        }

        self::assertSame(TicketTransferService::TOO_MANY, $service->offerToEmail(14, 1, 'x9@example.com', 'Dee Doe')['code']);
    }

    public function testTheGiverCancelsAWaitingOfferAndTheTicketIsTheirsAgain(): void
    {
        $service = $this->service();
        $service->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');

        self::assertSame(TicketTransferService::GONE, $service->cancelOffer(10, 2), 'only the giver cancels');
        self::assertSame(TicketTransferService::OK, $service->cancelOffer(10, 1));
        self::assertSame('cancelled', $this->db->transfers[1]['status']);
        self::assertNull($this->db->redemptions[10]['cancelled_at']);
        self::assertTrue($service->canGive($service->redemption(10), 1));
        self::assertSame(TicketTransferService::GONE, $service->accept(1, 'Dee')['code']);
    }

    public function testTheGiverCannotCancelOnceTheTicketHasMoved(): void
    {
        $service = $this->service();
        $service->giveToMember(10, 1, 2);

        self::assertSame(TicketTransferService::GONE, $service->cancelOffer(10, 1));
        self::assertSame('issued', $this->db->transfers[1]['status']);
        self::assertSame(1, $this->db->redemptions[10]['transferred_to']);
    }

    public function testAnOfferLapsesAtDoorOpening(): void
    {
        $service = $this->service();
        $service->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $this->at('17:00:00');

        self::assertNull($service->pendingOffer(10));
        self::assertSame('lapsed', $this->db->transfers[1]['status']);
        self::assertSame(TicketTransferService::GONE, $service->accept(1, 'Dee')['code']);
        self::assertNull($this->db->redemptions[10]['cancelled_at'], 'the ticket stays with the giver');
    }

    public function testNoNewOfferToAnEmailOnceTheDoorsAreOpen(): void
    {
        $this->at('17:30:00');

        self::assertSame(TicketTransferService::TOO_LATE, $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe')['code']);
        self::assertSame(TicketTransferService::OK, $this->service()->giveToMember(10, 1, 2)['code']);
    }

    public function testAGivenTicketCannotBeGivenOnOrGivenTwice(): void
    {
        $service = $this->service();
        $service->giveToMember(10, 1, 2);

        // The receiver holds a transfer, not a credit ticket of their own.
        self::assertSame(TicketTransferService::NOT_GIVABLE, $service->giveToMember(10, 2, 3)['code']);
        self::assertSame(TicketTransferService::NOT_GIVABLE, $service->giveToMember(10, 1, 3)['code']);
        self::assertSame(TicketTransferService::NOT_GIVABLE, $service->offerToEmail(10, 2, 'dee@example.com', 'Dee Doe')['code']);
        self::assertCount(1, $this->db->transfers);
    }

    public function testATicketUsedAtTheDoorCannotBeGiven(): void
    {
        $this->db->checkins[] = [
            'event_date' => self::NIGHT, 'kind' => CheckinRepository::KIND_CREDIT, 'ref_id' => 1,
            'checked_in_at' => self::NIGHT . ' 11:00:00',
        ];

        self::assertSame(TicketTransferService::NOT_GIVABLE, $this->service()->giveToMember(10, 1, 2)['code']);
    }

    public function testACrewTicketIsNeverOfferedAndACreditTicketIs(): void
    {
        $controller = new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            transfers: $this->service()
        );

        // assignments->find, tasks->find (the rest come from the arrays).
        $this->db->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 1, 'status' => 'signed_up'];
        $this->db->nextRows[] = [
            'id' => 5, 'task_date' => self::NIGHT, 'role_slug' => 'decorate',
            'capacity' => 2, 'event_post_id' => null, 'event_label' => 'Party',
        ];
        $crew = $controller->ticketFor(SignedLink::sign('ticket', 9));
        $credit = $controller->ticketFor(SignedLink::sign('credit_ticket', 10));

        self::assertNotNull($crew);
        self::assertArrayNotHasKey('give_url', $crew);
        self::assertNotNull($credit);
        self::assertStringContainsString('eventcrew/v1/ticket/give', (string) ($credit['give_url'] ?? ''));
    }

    public function testTheTicketPageSaysWhoTheTicketWentTo(): void
    {
        $service = $this->service();
        $service->giveToMember(10, 1, 2);
        $controller = new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            transfers: $service
        );

        $old = $controller->ticketFor(SignedLink::sign('credit_ticket', 10));
        $new = $controller->ticketFor(SignedLink::sign('xfer_ticket', 1));

        self::assertFalse($old['valid']);
        self::assertSame('Bob', $old['given_to'] ?? null);
        self::assertTrue($new['valid']);
        self::assertSame('Bob', $new['name']);
        self::assertStringContainsString('from Ann', $new['role']);
    }

    private function desk(): DoorDesk
    {
        return new DoorDesk(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            new GuestRepository(),
            new TicketBuyerRepository(),
            new CheckinRepository(),
            new AuthTokenRepository(),
            $this->standing(),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            ),
            $this->salesDeskGate(),
            new CreditGrantRepository(),
            null,
            null,
            new TicketTransferRepository()
        );
    }

    public function testAtTheDoorTheOldCodeIsRefusedAndTheNewCodeLetsTheReceiverIn(): void
    {
        $this->service()->giveToMember(10, 1, 2);
        $desk = $this->desk();

        $old = $desk->scan(self::NIGHT, TicketMail::creditCode(10));
        $new = $desk->scan(self::NIGHT, TicketMail::transferCode(1));

        self::assertSame(DoorDesk::SCAN_DISABLED, $old['code']);
        self::assertSame(DoorDesk::SCAN_OK, $new['code']);
        self::assertSame(CheckinRepository::KIND_TRANSFER . ':1', $new['key']);
        self::assertSame('Bob', $new['name']);
    }

    public function testWhileAnOfferWaitsTheGiversCodeStillWorksAndTheOfferHasNoCode(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $desk = $this->desk();

        self::assertSame(DoorDesk::SCAN_DISABLED, $desk->scan(self::NIGHT, TicketMail::transferCode(1))['code']);
        self::assertNotSame(DoorDesk::SCAN_DISABLED, $desk->scan(self::NIGHT, TicketMail::creditCode(10))['code']);
    }

    public function testAnOrganizerCancelsAGivenTicketAndItsCodeStops(): void
    {
        $service = $this->service();
        $service->giveToMember(10, 1, 2);

        self::assertTrue($service->cancelIssued(1));
        self::assertSame('cancelled', $this->db->transfers[1]['status']);
        self::assertSame(DoorDesk::SCAN_DISABLED, $this->desk()->scan(self::NIGHT, TicketMail::transferCode(1))['code']);
        self::assertSame(1, $this->db->redemptions[10]['transferred_to'], 'no credit comes back');
    }

    public function testTheDoorListShowsTheTransferredTicketFromTheGiverAndWhereTheOriginalWent(): void
    {
        $this->service()->giveToMember(10, 1, 2);
        $list = new DoorList(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            $this->standing(),
            new TicketTransferRepository()
        );

        $rows = array_map(
            static fn (array $entrant): string => $entrant['name'] . ': ' . $entrant['detail'],
            $list->entrantsFor(self::NIGHT)
        );

        self::assertSame(['Ann: Given to Bob', 'Bob: Transferred · from Ann'], $rows);
    }

    public function testTheDoorDeskHasATransferredSection(): void
    {
        $this->service()->giveToMember(10, 1, 2);
        $method = new \ReflectionMethod(DoorDesk::class, 'transferRows');
        $rows = $method->invoke($this->desk(), self::NIGHT);

        self::assertCount(1, $rows);
        self::assertSame('Bob', $rows[0]['name']);
        self::assertSame('from Ann', $rows[0]['detail']);
    }

    public function testErasingTheAddressBlanksTheGivenTicketAndEndsAWaitingOffer(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $people = new PersonRepository();
        $eraser = new PrivacyEraser(
            $people,
            new PersonEraser(
                $people,
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            ),
            transfers: new TicketTransferRepository()
        );

        $result = $eraser->erase('dee@example.com');

        self::assertTrue($result['items_removed']);
        self::assertStringContainsString('free-entry tickets given to this address', implode(' ', $result['messages']));
        self::assertNull($this->db->transfers[1]['email']);
        self::assertSame('cancelled', $this->db->transfers[1]['status']);
    }

    public function testRetentionDropsOldAddresses(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');

        self::assertSame(1, (new TicketTransferRepository())->anonymizeEmailsBefore('2026-08-01'));
        self::assertNull($this->db->transfers[1]['email']);
    }

    /**
     * @param string $data the callback data after the prefix
     */
    private function tap(TransferBot $bot, string $data, int $from = 501): void
    {
        $bot->onSelect(['id' => 'cb', 'from' => ['id' => $from], 'data' => TransferBot::PREFIX . $data]);
    }

    /** @return list<array{method: string, body: array<string, mixed>}> */
    private function sent(): array
    {
        return array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => 'sendMessage' === $call['method']
        ));
    }

    private function lastText(): string
    {
        $sent = $this->sent();

        return (string) ($sent[count($sent) - 1]['body']['text'] ?? '');
    }

    public function testTheBotGivesTheTicketToAMemberPickedByName(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        self::assertTrue($bot->isAwaiting(501));
        self::assertStringContainsString('Who gets your ticket?', $this->lastText());

        $bot->capture(501, 501, 'bo');
        $picker = $this->sent()[count($this->sent()) - 1]['body']['reply_markup']['inline_keyboard'];
        self::assertSame('Bob', $picker[0][0]['text']);
        self::assertSame('gv:p:2', $picker[0][0]['callback_data']);

        $this->tap($bot, 'p:2');
        self::assertStringContainsString('Give your ticket for', $this->lastText());
        self::assertStringContainsString('to Bob?', $this->lastText());

        $this->tap($bot, 'y');
        self::assertStringStartsWith('Done. Bob has your ticket for', $this->lastText());
        self::assertSame(2, $this->db->transfers[1]['receiver_person_id']);
    }

    public function testTheBotSendsAnOfferAndTheGiverCancelsIt(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'dee@example.com');
        self::assertSame('What is their name?', $this->lastText());
        self::assertTrue($bot->isAwaiting(501));

        $bot->capture(501, 501, '  Dee   Doe ');
        self::assertStringStartsWith('Offer your ticket for', $this->lastText());
        self::assertStringContainsString('to Dee Doe (dee@example.com)?', $this->lastText());

        $this->tap($bot, 'y');
        self::assertSame(
            'Sent. When Dee Doe accepts, the ticket moves to them. Until then your own ticket works.',
            $this->lastText()
        );
        self::assertSame('Dee Doe', $this->db->transfers[1]['name']);
        self::assertSame('dee@example.com', $this->db->transfers[1]['email']);
        self::assertSame('gv:x:10', $this->sent()[count($this->sent()) - 1]['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->tap($bot, 'g:10');
        self::assertSame(TicketTransferService::message(TicketTransferService::PENDING), $this->lastText());

        $this->tap($bot, 'x:10');
        self::assertSame('Offer cancelled. Your ticket is yours again.', $this->lastText());
        self::assertSame('cancelled', $this->db->transfers[1]['status']);
    }

    public function testTheBotRefusesTheGiversOwnAddressAndAsksAgain(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'ann@example.com');

        self::assertSame(TicketTransferService::message(TicketTransferService::SELF), $this->lastText());
        self::assertTrue($bot->isAwaiting(501));
    }

    public function testTheBotKeepsTheTicketOnKeepIt(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'dee@example.com');
        $bot->capture(501, 501, 'Dee Doe');
        $this->tap($bot, 'n');

        self::assertSame('Kept. The ticket stays with you.', $this->lastText());
        self::assertSame([], $this->db->transfers);
    }

    public function testTheBotAsksForTheNameAgainWhenItIsEmpty(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'dee@example.com');
        $bot->capture(501, 501, '<b></b>');

        self::assertSame('Write the name of the person who gets the ticket.', $this->lastText());
        self::assertTrue($bot->isAwaiting(501));

        $bot->capture(501, 501, 'Dee');
        self::assertStringContainsString('to Dee (dee@example.com)?', $this->lastText());
    }

    public function testTheBotDoesNotAskTheNameOfAMember(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'bob@example.com');

        self::assertStringContainsString('to Bob?', $this->lastText());
    }

    public function testSomeoneElseCannotGiveAnnsTicketFromTheBot(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10', 502);

        self::assertSame(TicketTransferService::message(TicketTransferService::NOT_GIVABLE), $this->lastText());
        self::assertFalse($bot->isAwaiting(502));
    }

    private function page(): TransferPage
    {
        $service = $this->service();
        $controller = new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            transfers: $service
        );

        return new TransferPage($service, new PersonRepository(), $controller);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request();
        $request->method = $method;
        $request->params = $params;

        return $request;
    }

    public function testTheGivePageFindsAMemberAndGivesTheTicket(): void
    {
        $token = SignedLink::sign('credit_ticket', 10);

        $ask = $this->page()->giveHtml($this->request('GET', ['ticket' => $token]));
        $find = $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'find', 'who' => 'Bob']));
        $done = $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'member', 'person' => 2]));

        self::assertStringContainsString('Who gets your ticket?', $ask);
        self::assertStringContainsString('Bob', $find);
        self::assertStringContainsString('Done. Bob has your ticket for', $done);
        self::assertSame(2, $this->db->transfers[1]['receiver_person_id']);
    }

    public function testTheGivePageAsksTheNameWithAnEmailAddress(): void
    {
        $token = SignedLink::sign('credit_ticket', 10);

        $find = $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'find', 'who' => 'dee@example.com']));
        $empty = $this->page()->giveHtml($this->request('POST', [
            'ticket' => $token, 'step' => 'email', 'email' => 'dee@example.com', 'name' => ' ',
        ]));

        self::assertStringContainsString('Offer your ticket for', $find);
        self::assertStringContainsString('Their name', $find);
        self::assertStringContainsString('name="name"', $find);
        self::assertStringContainsString('Write the name of the person who gets the ticket.', $empty);
        self::assertStringContainsString('Their name', $empty);
        self::assertSame([], $this->db->transfers);

        $sent = $this->page()->giveHtml($this->request('POST', [
            'ticket' => $token, 'step' => 'email', 'email' => 'dee@example.com', 'name' => 'Dee Doe',
        ]));
        $again = $this->page()->giveHtml($this->request('GET', ['ticket' => $token]));

        self::assertStringContainsString('Sent. When Dee Doe accepts', $sent);
        self::assertSame('Dee Doe', $this->db->transfers[1]['name']);
        self::assertStringContainsString('Waiting for Dee Doe to accept', $again);
    }

    public function testTheWaitingLineNamesTheReceiver(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');

        $credit = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 10));
        $give = $this->profileGive();

        self::assertSame('Dee Doe', $credit['offer_to'] ?? null);
        self::assertSame(['⏳ Waiting for Dee Doe to accept'], $give['lines']);
    }

    public function testTheAcceptPageShowsTheGivenNameAndTheReceiverCanCorrectIt(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Do');
        $offer = SignedLink::sign(TicketTransferService::ACCEPT_PURPOSE, 1);

        $form = $this->page()->acceptHtml($this->request('GET', ['offer' => $offer]));
        $empty = $this->page()->acceptHtml($this->request('POST', ['offer' => $offer, 'name' => '']));

        self::assertStringContainsString('value="Dee Do"', $form);
        self::assertStringContainsString('Please write your name.', $empty);
        self::assertStringContainsString('value=""', $empty);
        self::assertSame('pending', $this->db->transfers[1]['status']);

        $this->page()->acceptHtml($this->request('POST', ['offer' => $offer, 'name' => 'Dee Doe']));

        self::assertSame('Dee Doe', $this->db->transfers[1]['name']);
        self::assertSame('issued', $this->db->transfers[1]['status']);
    }

    public function testTheAcceptPageAsksForTheNameThenIssuesTheTicket(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $offer = SignedLink::sign(TicketTransferService::ACCEPT_PURPOSE, 1);

        $form = $this->page()->acceptHtml($this->request('GET', ['offer' => $offer]));
        $done = $this->page()->acceptHtml($this->request('POST', ['offer' => $offer, 'name' => 'Dee']));

        self::assertStringContainsString('Ann is giving you their free-entry ticket for', $form);
        self::assertStringContainsString('Your name', $form);
        self::assertStringContainsString('The ticket is yours. We emailed it to dee@example.com.', $done);
        self::assertSame('Dee', $this->db->transfers[1]['name']);
    }

    public function testTheAcceptPageOfACancelledOfferSaysItIsGone(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $this->service()->cancelOffer(10, 1);

        $page = $this->page()->acceptHtml($this->request('GET', [
            'offer' => SignedLink::sign(TicketTransferService::ACCEPT_PURPOSE, 1),
        ]));

        self::assertStringContainsString('This offer is no longer open.', $page);
    }

    /** The setting "Members can give away their ticket" turned off. */
    private function givingOff(): void
    {
        $this->options[TicketTransferService::OPTION] = '0';
    }

    private function controller(?TicketTransferService $service = null): TicketController
    {
        return new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            transfers: $service ?? $this->service()
        );
    }

    /**
     * The /me tickets give-away part for Ann's ticket 10: its buttons and lines.
     *
     * @return array{buttons: array<int, mixed>, lines: list<string>}
     */
    private function profileGive(): array
    {
        $profile = new ProfileService(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            $this->standing(),
            $this->client(),
            $this->service()
        );
        $method = new \ReflectionMethod($profile, 'giveAway');
        $buttons = [];
        $lines = $method->invokeArgs($profile, [['redemption_id' => 10, 'when' => 'Mon 20 Jul'], 1, &$buttons]);

        return ['buttons' => $buttons, 'lines' => $lines];
    }

    public function testTheSettingIsOnUnlessTurnedOff(): void
    {
        self::assertTrue(TicketTransferService::isOn());

        TicketTransferService::save(false);
        self::assertSame('0', $this->options[TicketTransferService::OPTION]);
        self::assertFalse(TicketTransferService::isOn());

        TicketTransferService::save(true);
        self::assertTrue(TicketTransferService::isOn());
    }

    public function testWithGivingOnTheBotOffersGiveAwayUnderTheTicket(): void
    {
        $give = $this->profileGive();

        self::assertSame('gv:g:10', $give['buttons'][0][0]['callback_data'] ?? null);
        self::assertSame([], $give['lines']);
    }

    public function testWithGivingOffNoPlaceOffersGiveAway(): void
    {
        $this->givingOff();

        $credit = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 10));
        $give = $this->profileGive();

        self::assertNotNull($credit);
        self::assertArrayNotHasKey('give_url', $credit);
        self::assertSame([], $give['buttons']);
        self::assertSame([], $give['lines']);
    }

    public function testWithGivingOffAWaitingOfferIsNotShownAndCannotBeAccepted(): void
    {
        $this->service()->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe');
        $this->givingOff();
        $offer = SignedLink::sign(TicketTransferService::ACCEPT_PURPOSE, 1);

        $credit = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 10));
        $give = $this->profileGive();
        $form = $this->page()->acceptHtml($this->request('GET', ['offer' => $offer]));
        $post = $this->page()->acceptHtml($this->request('POST', ['offer' => $offer, 'name' => 'Dee']));
        $accept = $this->service()->accept(1, 'Dee');

        self::assertArrayNotHasKey('offer_to', $credit);
        self::assertArrayNotHasKey('give_url', $credit);
        self::assertSame([], $give['buttons']);
        self::assertSame([], $give['lines']);
        self::assertStringContainsString('Giving tickets away is turned off.', $form);
        self::assertStringNotContainsString('Your name', $form);
        self::assertStringContainsString('Giving tickets away is turned off.', $post);
        self::assertSame(TicketTransferService::DISABLED, $accept['code']);
        self::assertSame('pending', $this->db->transfers[1]['status']);
        self::assertNull($this->db->redemptions[10]['transferred_to'] ?? null);
        self::assertNotSame(DoorDesk::SCAN_DISABLED, $this->desk()->scan(self::NIGHT, TicketMail::creditCode(10))['code']);

        // The giver's old give link still cancels the offer.
        $token = SignedLink::sign('credit_ticket', 10);
        $page = $this->page()->giveHtml($this->request('GET', ['ticket' => $token]));
        self::assertStringContainsString('Giving tickets away is turned off.', $page);
        self::assertStringNotContainsString('Waiting for', $page);
        $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'cancel']));
        self::assertSame('cancelled', $this->db->transfers[1]['status']);
    }

    public function testWithGivingOffTheServiceRefusesEveryGive(): void
    {
        $this->givingOff();
        $service = $this->service();

        self::assertSame(TicketTransferService::DISABLED, $service->refusal($service->redemption(10), 1));
        self::assertFalse($service->canGive($service->redemption(10), 1));
        self::assertSame(TicketTransferService::DISABLED, $service->giveToMember(10, 1, 2)['code']);
        self::assertSame(TicketTransferService::DISABLED, $service->offerToEmail(10, 1, 'dee@example.com', 'Dee Doe')['code']);
        self::assertSame([], $this->db->transfers);
        self::assertNull($this->db->redemptions[10]['cancelled_at']);
    }

    public function testWithGivingOffAnOldBotButtonIsRefused(): void
    {
        $this->givingOff();
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');

        self::assertSame('Giving tickets away is turned off.', $this->lastText());
        self::assertFalse($bot->isAwaiting(501));
    }

    public function testTurningGivingOffMidwayStopsTheBotsGive(): void
    {
        $bot = new TransferBot($this->service(), new PersonRepository(), $this->client());

        $this->tap($bot, 'g:10');
        $bot->capture(501, 501, 'bo');
        $this->givingOff();
        $this->tap($bot, 'p:2');
        self::assertSame('Giving tickets away is turned off.', $this->lastText());

        $this->options[TicketTransferService::OPTION] = '1';
        $this->tap($bot, 'g:10');
        $this->givingOff();
        $bot->capture(501, 501, 'dee@example.com');
        self::assertSame('Giving tickets away is turned off.', $this->lastText());

        $this->tap($bot, 'y');
        self::assertSame('Giving tickets away is turned off.', $this->lastText());
        self::assertSame([], $this->db->transfers);
    }

    public function testWithGivingOffTheGivePageRefuses(): void
    {
        $this->givingOff();
        $token = SignedLink::sign('credit_ticket', 10);

        $ask = $this->page()->giveHtml($this->request('GET', ['ticket' => $token]));
        $done = $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'member', 'person' => 2]));
        $mail = $this->page()->giveHtml($this->request('POST', ['ticket' => $token, 'step' => 'email', 'email' => 'dee@example.com']));

        self::assertStringContainsString('Giving tickets away is turned off.', $ask);
        self::assertStringNotContainsString('Who gets your ticket?', $ask);
        self::assertStringContainsString('Giving tickets away is turned off.', $done);
        self::assertStringContainsString('Giving tickets away is turned off.', $mail);
        self::assertSame([], $this->db->transfers);
    }

    public function testWithGivingOffATicketAlreadyGivenStaysValid(): void
    {
        $service = $this->service();
        $service->giveToMember(10, 1, 2);
        $this->givingOff();

        $old = $this->controller($service)->ticketFor(SignedLink::sign('credit_ticket', 10));
        $new = $this->controller($service)->ticketFor(SignedLink::sign('xfer_ticket', 1));
        $scan = $this->desk()->scan(self::NIGHT, TicketMail::transferCode(1));

        self::assertSame('Bob', $old['given_to'] ?? null);
        self::assertTrue($new['valid']);
        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('Bob', $scan['name']);
    }
}
