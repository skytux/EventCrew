<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * A <form> inside another form is dropped by the browser, and its </form>
 * ends the outer one: the inner form's hidden "action" then posts with the
 * outer form. That is how the Settings page's Save ran "Test the webhook"
 * (1.140.34 - 1.140.88). Every admin and public template keeps its forms flat.
 */
final class AdminFormsNotNestedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = [$file->getPathname()];
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @dataProvider templates
     */
    public function testNoFormIsInsideAnotherForm(string $path): void
    {
        $source = (string) file_get_contents($path);
        preg_match_all('#<(/?)form\b#i', $source, $tags, PREG_OFFSET_CAPTURE);

        $depth = 0;

        foreach ($tags[1] as [$slash, $offset]) {
            $depth += '' === $slash ? 1 : -1;
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $this->assertLessThanOrEqual(1, $depth, basename($path) . ': a form opens inside another form at line ' . $line);
            $this->assertGreaterThanOrEqual(0, $depth, basename($path) . ': a </form> without a form at line ' . $line);
        }

        $this->assertSame(0, $depth, basename($path) . ': a form is not closed');
    }
}
