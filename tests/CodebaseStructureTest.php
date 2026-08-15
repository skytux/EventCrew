<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Structural checks that stand in for tooling this project deliberately does
 * without.
 *
 * The plugin ships a hand-rolled PSR-4 autoloader and renders templates by
 * slug, so a class left in a file that no longer matches its name, or a page
 * pointing at a template that no longer exists, produces a runtime fatal or a
 * wp_die rather than anything a unit test would notice. A bulk rename is
 * exactly the operation that breaks both, so both are asserted here.
 */
final class CodebaseStructureTest extends TestCase
{
    public function testEveryClassLivesInTheFileItsAutoloaderWouldLookIn(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (1 !== preg_match('/^\s*(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $contents, $matches)) {
                continue;
            }

            self::assertSame(
                $file->getBasename('.php'),
                $matches[1],
                sprintf(
                    'Class %s is in %s; the autoloader resolves a class name straight to a filename, so it would never be found.',
                    $matches[1],
                    $file->getFilename()
                )
            );
        }
    }

    public function testEveryClassDeclaresTheNamespaceItsDirectoryImplies(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $contents, $matches)) {
                continue;
            }

            $relativeDir = trim(
                str_replace(
                    [$this->sourceDir(), DIRECTORY_SEPARATOR, '/'],
                    ['', '\\', '\\'],
                    $file->getPath()
                ),
                '\\'
            );

            $expected = rtrim('EventCrew\\' . $relativeDir, '\\');

            self::assertSame(trim($matches[1]), $expected, $file->getFilename());
        }
    }

    /**
     * Admin pages name their template as a bare slug, which View turns into
     * templates/admin/<slug>.php. Renaming one side without the other is
     * silent until someone opens the screen.
     */
    public function testEveryTemplateRenderedByAPageExistsOnDisk(): void
    {
        $found = 0;

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (0 === preg_match_all("/->render\(\s*'([a-z0-9_-]+)'/", $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $slug) {
                ++$found;

                self::assertFileExists(
                    EVENTCREW_PLUGIN_DIR . 'templates/admin/' . $slug . '.php',
                    sprintf('%s renders template "%s", which does not exist.', $file->getFilename(), $slug)
                );
            }
        }

        self::assertGreaterThan(0, $found, 'No template renders were found to check; the pattern has drifted.');
    }

    /**
     * The old vocabulary must not creep back in. array_shift is the one
     * legitimate occurrence of the substring in the codebase.
     */
    public function testNoSourceFileStillUsesTheRetiredVocabulary(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = str_replace('array_shift', '', (string) file_get_contents($file->getPathname()));

            self::assertDoesNotMatchRegularExpression(
                '/volunteer|shift/i',
                $contents,
                sprintf('%s still uses "volunteer" or "shift"; the domain vocabulary is person and task.', $file->getFilename())
            );
        }
    }

    /**
     * Every form on the crew page that posts an action must carry the CSRF
     * token, because every action behind the sign-in gate is checked for it.
     *
     * This exists because of a real regression: v1.20.0 moved sign-out behind
     * that gate without adding the token to its form, so the button answered
     * "please sign in" to somebody who was signed in and asking to stop being.
     * Nothing caught it - the handler is the one path the unit tests skip, since
     * it ends in exit() - and the shape of the mistake (a form and a handler
     * that stopped agreeing) is exactly what a structural check can see.
     *
     * The sign-in form is the one exemption: it is posted by somebody who has
     * no session yet, and its action is handled before the gate.
     */
    public function testEveryPostingFormOnTheCrewPageCarriesTheCsrfToken(): void
    {
        $template = (string) file_get_contents(
            EVENTCREW_PLUGIN_DIR . 'templates/public/signup.php'
        );

        // Split on the opening tag so each chunk is one form's markup.
        $forms = array_slice(explode('<form', $template), 1);
        $checked = 0;

        foreach ($forms as $form) {
            $form = explode('</form>', $form)[0];

            // Only the ones posting to admin-ajax; the account-delete form goes
            // to the signed manage endpoint and carries a token of its own.
            if (! str_contains($form, '$eventcrew_ajax')) {
                continue;
            }

            if (str_contains($form, 'data-eventcrew-signin')) {
                continue;
            }

            ++$checked;

            self::assertStringContainsString(
                'name="csrf"',
                $form,
                'A form on the crew page posts an action without the CSRF token, so the handler will refuse it.'
            );
        }

        self::assertGreaterThan(0, $checked, 'No posting forms were found to check; the pattern has drifted.');
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDir(), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function sourceDir(): string
    {
        return EVENTCREW_PLUGIN_DIR . 'src';
    }
}
