<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Tests\TestCase;
use EventCrew\Web\CrewAdminNewsletter;

/**
 * The announcement text field of the crew app's Admin tab shows the opening
 * the language model already wrote for the plan while there is no
 * organizers' text, and saving it unchanged stores nothing.
 */
final class CrewAdminNewsletterSuggestionTest extends TestCase
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
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('determine_locale')->justReturn('en_US');
    }

    private function screen(): CrewAdminNewsletter
    {
        $logger = new Logger();
        $text = new NewsletterText(new LlmClient($logger), $logger);

        return new CrewAdminNewsletter(
            new Newsletter(
                new NewsletterSubscriberRepository(),
                new PersonRepository(),
                $text,
                new Mailer($logger, new EmailTemplate($logger)),
                $logger
            ),
            $text
        );
    }

    /** @param array<string, string>|null $opening */
    private function plan(?array $opening): void
    {
        $this->options[Newsletter::STATE_OPTION] = ['plan' => [
            'id' => 1,
            'status' => Newsletter::PLAN_PLANNED,
            'send_on' => '2026-07-25',
            'events' => [],
            'written' => null === $opening ? [] : ['opening' => $opening],
        ]];
    }

    public function testTheFieldShowsTheWrittenOpeningInTheViewersLanguage(): void
    {
        $this->plan(['fi' => 'Tervetuloa tanssimaan', 'en' => 'Welcome to dance']);

        self::assertSame('Welcome to dance', $this->screen()->view()['suggested']);
    }

    public function testTheFieldIsEmptyWithoutAPlanOrAnOpening(): void
    {
        self::assertSame('', $this->screen()->view()['suggested']);

        $this->plan(null);
        self::assertSame('', $this->screen()->view()['suggested']);
    }

    public function testTheFieldIsEmptyWhenTheOrganizersWroteAText(): void
    {
        $this->plan(['en' => 'Welcome to dance']);
        $this->options[NewsletterText::OPTION] = ['en' => 'Our own words', 'by' => 10, 'rev' => 1];

        self::assertSame('', $this->screen()->view()['suggested']);
    }

    public function testSavingTheSuggestionUnchangedStoresNothing(): void
    {
        $this->plan(['fi' => 'Tervetuloa tanssimaan', 'en' => 'Welcome to dance']);

        self::assertSame('admin_newsletter_suggested', $this->screen()->saveText(9, 'save', "Welcome to dance\r\n"));
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
    }
}
