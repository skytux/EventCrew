<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupOptOut;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\TestCase;
use EventCrew\Web\FollowupController;

/**
 * The organizers' Stop link: opening it only shows a button (a mail
 * scanner must not stop the email), pressing it stops that night.
 */
final class FollowupControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [FollowupMail::ENABLED_OPTION => '1'];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
    }

    public function testABadTokenIsNotFound(): void
    {
        $page = $this->controller()->respond('stop', 'POST', 'nonsense');

        self::assertSame(404, $page['status']);
        self::assertStringContainsString('no longer valid', $page['html']);
        self::assertArrayNotHasKey(FollowupMail::STATE_OPTION, $this->options);
    }

    public function testOpeningTheLinkOnlyShowsTheButton(): void
    {
        $token = FollowupMail::stopToken('2026-11-14');
        $page = $this->controller()->respond('stop', 'GET', $token);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<form method="post"', $page['html']);
        self::assertStringContainsString('value="' . $token . '"', $page['html']);
        self::assertStringContainsString(
            'Älä lähetä tapahtuman jälkeistä viestiä / Do not send the post-event email',
            $page['html']
        );
        self::assertArrayNotHasKey(FollowupMail::STATE_OPTION, $this->options, 'nothing stopped yet');
    }

    public function testPressingTheButtonStopsThatNight(): void
    {
        $page = $this->controller()->respond('stop', 'POST', FollowupMail::stopToken('2026-11-14'));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('The post-event email is stopped.', $page['html']);
        self::assertStringContainsString('Tapahtuman jälkeinen viesti on pysäytetty.', $page['html']);
        self::assertTrue($this->options[FollowupMail::STATE_OPTION]['stopped']['2026-11-14'] ?? false);

        $again = $this->controller()->respond('stop', 'POST', FollowupMail::stopToken('2026-11-14'));
        self::assertStringContainsString('was already stopped', $again['html']);
    }

    /** Opening the subscribe link (as a mail scanner does) subscribes nobody and counts no click. */
    public function testOpeningTheSubscribeLinkOnlyShowsAFormThatPostsItBack(): void
    {
        $token = self::joinToken('b', 7);
        $answer = $this->controller()->respondJoin('GET', $token, 'fi');

        self::assertNull($answer['redirect']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $answer['html']);
        self::assertStringContainsString('<form id="eventcrew-join" method="post"', $answer['html']);
        self::assertStringContainsString(
            'name="' . FollowupMail::JOIN_ARG . '" value="' . $token . '"',
            $answer['html']
        );
        self::assertStringContainsString('>Liity / Join</button>', $answer['html']);
        self::assertStringContainsString('document.getElementById("eventcrew-join").submit()', $answer['html']);
        self::assertSame([], $this->wpdb->queries, 'no click counted, no row read');
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testPostingTheSubscribeTokenSubscribesTheTicketHolder(): void
    {
        $this->wpdb->nextQueryResults = [1];
        $this->wpdb->nextRows[] = ['name' => 'Anna Virtanen', 'email' => 'anna@example.test']; // holder()
        $this->wpdb->nextRows[] = null; // no subscriber row yet

        $answer = $this->controller()->respondJoin('POST', self::joinToken('b', 7), 'en');

        self::assertStringStartsWith('https://site.test/?eventcrew_newsletter=joined', (string) $answer['redirect']);
        self::assertStringContainsString('followup_clicked_at', implode("\n", $this->wpdb->queries));
        self::assertSame('anna@example.test', $this->wpdb->inserts[0]['data']['email']);
        self::assertSame('en', $this->wpdb->inserts[0]['data']['language']);
    }

    public function testAForgedOrSampleSubscribeTokenSubscribesNobody(): void
    {
        $controller = $this->controller();

        foreach (['GET', 'POST'] as $method) {
            self::assertSame('https://site.test/', $controller->respondJoin($method, 'garbage', 'fi')['redirect']);
            self::assertSame(
                'https://site.test/',
                $controller->respondJoin($method, self::joinToken('b', 7) . 'x', 'fi')['redirect']
            );
        }

        $sample = $controller->respondJoin('POST', self::joinToken('t', 0), 'fi');
        self::assertStringNotContainsString('joined', (string) $sample['redirect']);

        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    /** Opening "Don't send me these" (as a mail scanner does) puts nobody on the list. */
    public function testOpeningTheOptOutLinkOnlyShowsAFormThatPostsItBack(): void
    {
        $token = self::optOutToken('b', 7);
        $page = $this->controller()->respondOptOut('GET', $token);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $page['html']);
        self::assertStringContainsString('<form id="eventcrew-optout" method="post"', $page['html']);
        self::assertStringContainsString(
            'name="' . FollowupMail::OPTOUT_ARG . '" value="' . $token . '"',
            $page['html']
        );
        self::assertStringContainsString('document.getElementById("eventcrew-optout").submit()', $page['html']);
        self::assertStringContainsString('stop getting these emails', $page['html']);
        self::assertSame([], $this->wpdb->queries, 'no row read');
        self::assertArrayNotHasKey(FollowupOptOut::OPTION, $this->options);
    }

    public function testPostingTheOptOutTokenPutsTheAddressOnTheListAndConfirms(): void
    {
        $this->wpdb->nextRows[] = ['name' => 'Anna Virtanen', 'email' => 'anna@example.test']; // holder()

        $page = $this->controller()->respondOptOut('POST', self::optOutToken('g', 3));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('You will not get these emails after our events any more.', $page['html']);
        self::assertSame(['anna@example.test'], array_keys($this->options[FollowupOptOut::OPTION]));
    }

    /** The page is in the post-event email's languages: only the main one with the Hybrid tick off. */
    public function testTheOptOutPageIsInTheSiteLanguage(): void
    {
        $this->options[HybridPolicy::OPTION] = [HybridPolicy::FOLLOWUP => false];
        $this->wpdb->nextRows[] = null; // holder(): no row

        $page = $this->controller()->respondOptOut('POST', self::optOutToken('b', 7));

        self::assertStringContainsString('<html lang="' . SiteLanguages::main() . '">', $page['html']);
        self::assertSame(1, substr_count($page['html'], '<p lang='));
    }

    public function testAForgedOptOutTokenIsNotFound(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $page = $this->controller()->respondOptOut($method, self::optOutToken('b', 7) . 'x');

            self::assertSame(404, $page['status']);
            self::assertStringContainsString('no longer valid', $page['html']);
        }

        self::assertSame([], $this->wpdb->queries);
        self::assertArrayNotHasKey(FollowupOptOut::OPTION, $this->options);
    }

    private static function optOutToken(string $kind, int $id): string
    {
        parse_str((string) parse_url(FollowupMail::optOutUrl($kind, $id), PHP_URL_QUERY), $query);

        return (string) $query[FollowupMail::OPTOUT_ARG];
    }

    private static function joinToken(string $kind, int $id): string
    {
        $url = FollowupMail::joinUrl($kind, $id, 'thanks-2026-11-14');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query[FollowupMail::JOIN_ARG];
    }

    private function controller(): FollowupController
    {
        $logger = new Logger();

        return new FollowupController(new FollowupMail(
            new FollowupRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new Mailer($logger, new EmailTemplate($logger)),
            $logger
        ));
    }
}
