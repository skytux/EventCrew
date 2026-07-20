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
