<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\SignedLink;
use EventCrew\Telegram\ManageController;
use EventCrew\Tests\TestCase;
use WP_REST_Request;

final class ManageControllerTest extends TestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('get_option')->justReturn(false);
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    /**
     * @param array<string, string> $params
     */
    private function request(string $method, array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->method = $method;
        $request->params = $params;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(): array
    {
        return ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
    }

    public function testTheReusableLinkShowsTheAccountPageAndDeletesNothing(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();

        $page = $this->controller()->respond($this->request('GET', ['token' => SignedLink::sign('manage', 7)]));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('sam@example.com', $page['html']);
        self::assertStringContainsString('Delete my data', $page['html']);
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testDeleteOnTheReusableLinkOnlyMailsAOneTimeLink(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();

        $page = $this->controller()->respond($this->request('POST', [
            'token' => SignedLink::sign('manage', 7),
            'action' => ManageController::DELETE,
        ]));

        // Nothing is deleted yet.
        self::assertSame([], $this->wpdb->deletes);
        // A 30-minute one-time token for person 7 ...
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(7, $this->wpdb->inserts[0]['data']['person_id']);
        self::assertSame(ManageController::DELETE_PURPOSE, $this->wpdb->inserts[0]['data']['purpose']);
        self::assertSame('2026-07-20 12:30:00', $this->wpdb->inserts[0]['data']['expires_at']);
        // ... mailed to the person's own address, as a link to this page.
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertSame('Confirm deleting your data', $this->mails[0]['subject']);
        self::assertStringContainsString(
            'https://site.test/wp-json/eventcrew/v1/manage?delete=',
            $this->mails[0]['body']
        );
        self::assertStringContainsString(
            'We sent a link to sam@example.com. Open it within 30 minutes to delete your data.',
            $page['html']
        );
    }

    public function testTheOneTimeLinkOpenedShowsTheButtonAndDeletesNothing(): void
    {
        $page = $this->controller()->respond($this->request('GET', ['delete' => 'rawtoken']));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('name="delete" value="rawtoken"', $page['html']);
        self::assertStringContainsString('Delete my data', $page['html']);
        // Opening the link (or a mail scanner fetching it) spends nothing.
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testTheButtonOnTheOneTimeLinkDeletesOnce(): void
    {
        // consumeOnce: a live token for person 7, and its UPDATE changes the row.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2026-07-20 12:30:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->personRow();

        $page = $this->controller()->respond($this->request('POST', [
            'delete' => 'rawtoken',
            'action' => ManageController::DELETE,
        ]));

        self::assertStringContainsString('Your data has been deleted.', $page['html']);
        self::assertCount(8, $this->wpdb->deletes);
        self::assertStringContainsString("purpose = 'manage_delete'", implode("\n", $this->wpdb->queries));
    }

    public function testASpentOrUnknownOneTimeLinkDeletesNothing(): void
    {
        // The row is already used.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2026-07-20 12:30:00',
            'used_at' => '2026-07-20 12:01:00',
        ];

        $page = $this->controller()->respond($this->request('POST', [
            'delete' => 'rawtoken',
            'action' => ManageController::DELETE,
        ]));

        self::assertSame(404, $page['status']);
        self::assertStringContainsString('This link is no longer valid.', $page['html']);
        self::assertSame([], $this->wpdb->deletes);
    }

    private function controller(): ManageController
    {
        return new ManageController(
            new PersonRepository(),
            new PersonEraser(
                new PersonRepository(),
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            ),
            new AuthTokenRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    public function testDeleteRemovesThePersonTheirAssignmentsAndRedemptions(): void
    {
        $outcome = $this->controller()->apply(7, ManageController::DELETE);

        self::assertSame(ManageController::DELETE, $outcome);
        // Sign-in tokens, the send ledger, the assignments, the redemptions,
        // the credit grants and the person row are all deleted. The first two
        // were missed before PersonEraser: an "erased" person kept a live magic
        // link and a record of every notice they had been sent. Takeover
        // requests are two more deletes: as holder and as the one who asked.
        self::assertCount(8, $this->wpdb->deletes);
    }

    public function testAnUnknownActionDoesNothing(): void
    {
        $outcome = $this->controller()->apply(7, 'whatever');

        self::assertSame('', $outcome);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->deletes);
    }
}
