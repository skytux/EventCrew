<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Tests\TestCase;
use EventCrew\Web\BuyerImportController;
use WP_REST_Request;

/**
 * The import-by-email endpoint. "Today" is 2026-07-20 (the stubbed current_time).
 */
final class BuyerImportControllerTest extends TestCase
{
    private const SECRET = 'test-import-secret';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            BuyerImportController::SECRET_OPTION => self::SECRET,
            EmailTemplate::HTML_OPTION => false,
        ];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('wp_generate_password')->justReturn('a-new-generated-secret');
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function controller(): BuyerImportController
    {
        return new BuyerImportController(
            new PersonRepository(),
            new BuyerImport(
                new TicketBuyerRepository(),
                new DoorAccess(new TaskRepository(), new AssignmentRepository())
            ),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, ?string $signature = null): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->method = 'POST';
        $request->body = (string) json_encode($payload);
        $request->set_header(
            'X-EventCrew-Signature',
            $signature ?? 'sha256=' . hash_hmac('sha256', $request->body, self::SECRET)
        );

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $from = 'lou@example.com'): array
    {
        return ['from' => $from, 'text' => "Anna\nBo", 'message_id' => '<m1@example.com>'];
    }

    private function queueOrganizer(bool $isOrganizer = true): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'lou@example.com',
            'display_name' => 'Lou',
            'is_organizer' => $isOrganizer ? 1 : 0,
        ];
    }

    /**
     * @return array<int, array{table: string, data: array<string, mixed>}>
     */
    private function buyerInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'eventcrew_ticket_buyers')
        ));
    }

    // --- signature ---------------------------------------------------------

    public function testAcceptsTheRightSignature(): void
    {
        self::assertTrue($this->controller()->authorize($this->request($this->payload())));
    }

    public function testRefusesAWrongSignature(): void
    {
        $bad = 'sha256=' . hash_hmac('sha256', (string) json_encode($this->payload()), 'wrong-secret');

        self::assertFalse($this->controller()->authorize($this->request($this->payload(), $bad)));
    }

    public function testRefusesASignatureOfADifferentBody(): void
    {
        $request = $this->request($this->payload());
        // Same header, body changed in transit.
        $request->body = (string) json_encode($this->payload('someone@else.com'));

        self::assertFalse($this->controller()->authorize($request));
    }

    public function testRefusesAMissingSignature(): void
    {
        self::assertFalse($this->controller()->authorize($this->request($this->payload(), '')));
    }

    public function testRefusesEverythingWhenNoSecretIsStored(): void
    {
        $this->options[BuyerImportController::SECRET_OPTION] = '';
        // The signature an attacker could make with an empty key.
        $request = $this->request($this->payload());
        $request->set_header('X-EventCrew-Signature', 'sha256=' . hash_hmac('sha256', $request->body, ''));

        self::assertFalse($this->controller()->authorize($request));
    }

    public function testEnsureSecretGeneratesOneOnlyWhenNoneIsStored(): void
    {
        self::assertSame(self::SECRET, BuyerImportController::ensureSecret());

        $this->options[BuyerImportController::SECRET_OPTION] = '';

        self::assertSame('a-new-generated-secret', BuyerImportController::ensureSecret());
        self::assertSame('a-new-generated-secret', $this->options[BuyerImportController::SECRET_OPTION]);
    }

    // --- handling ----------------------------------------------------------

    public function testAnOrganizersListIsImportedToTheNextNightAndConfirmed(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10']; // datesWithTasks
        $this->wpdb->nextVars[] = '0'; // hasBatch: new mail
        $this->wpdb->nextResults[] = []; // nobody on the list yet

        $response = $this->controller()->handle($this->request($this->payload()));

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['ok' => true, 'added' => 2, 'skipped' => 0, 'date' => '2026-08-01', 'duplicate' => false],
            $response->data
        );

        $inserts = $this->buyerInserts();
        self::assertCount(2, $inserts);
        self::assertSame('2026-08-01', $inserts[0]['data']['event_date']);
        self::assertSame('email', $inserts[0]['data']['source']);
        self::assertSame(BuyerImport::batchFor('<m1@example.com>', ''), $inserts[0]['data']['batch']);

        self::assertCount(1, $this->mails);
        self::assertSame('lou@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('2 ticket buyers were added', $this->mails[0]['body']);
        self::assertStringContainsString('page=eventcrew-roster', $this->mails[0]['body']);
        self::assertStringContainsString('roster_date=2026-08-01', $this->mails[0]['body']);
    }

    public function testANonOrganizerIsRefusedAndNothingIsImported(): void
    {
        $this->queueOrganizer(false);

        $response = $this->controller()->handle($this->request($this->payload()));

        self::assertSame(403, $response->get_status());
        self::assertSame('not_organizer', $response->data['error']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame([], $this->mails);
    }

    public function testAnUnknownSenderIsRefused(): void
    {
        // No person row queued: the address is not in the crew.
        $response = $this->controller()->handle($this->request($this->payload('stranger@example.com')));

        self::assertSame(403, $response->get_status());
        self::assertSame([], $this->buyerInserts());
    }

    public function testTheSameMailTwiceAddsNothingAndSendsNoSecondConfirmation(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextVars[] = '2'; // hasBatch: this Message-ID was imported already

        $response = $this->controller()->handle($this->request($this->payload()));

        self::assertSame(200, $response->get_status());
        self::assertSame(0, $response->data['added']);
        self::assertTrue($response->data['duplicate']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame([], $this->mails);
    }

    public function testNoUpcomingNightIsRefusedWithAClearMessage(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-07-19'];

        $response = $this->controller()->handle($this->request($this->payload()));

        self::assertSame(409, $response->get_status());
        self::assertSame('no_upcoming_event', $response->data['error']);
        self::assertStringContainsString('no upcoming event', $response->data['message']);
        self::assertSame([], $this->buyerInserts());
    }

    public function testABodyThatIsNotJsonIsABadRequest(): void
    {
        $request = new WP_REST_Request();
        $request->body = 'not json';

        self::assertSame(400, $this->controller()->handle($request)->get_status());
    }
}
