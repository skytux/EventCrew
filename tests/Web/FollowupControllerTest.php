<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
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
