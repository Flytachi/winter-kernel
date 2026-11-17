<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\FrontBuild;
use PHPUnit\Framework\TestCase;

/** How the image builds a frontend: what a Dockerfile can take, and nothing that empties the app. */
final class FrontBuildTest extends TestCase
{
    public function test_a_folder_of_the_project_is_taken(): void
    {
        self::assertSame('apps/web', new FrontBuild('apps/web', '.output/public', ['package.json'], 'i', 'b')->context);
    }

    public function test_the_project_itself_or_a_dot_segment_is_refused(): void
    {
        // The image empties the frontend's folder before its build is copied in: `.` would be
        // the whole application.
        foreach (['.', './web', 'web/.', 'web/./admin', '..', 'web/../x'] as $context) {
            try {
                new FrontBuild($context, '.output/public', ['package.json'], 'i', 'b');
                self::fail("accepted: {$context}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('context', $e->getMessage());
            }
        }
    }
}
