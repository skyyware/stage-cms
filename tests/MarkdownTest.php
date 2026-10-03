<?php
declare(strict_types=1);

namespace StageCms\Tests;

use PHPUnit\Framework\TestCase;
use StageCms\Presentation\Markdown;

final class MarkdownTest extends TestCase
{
    public function testTablesRetainTheContentSafetyBoundary(): void
    {
        $html = (new Markdown())->render("| Method | Result |\n| --- | --- |\n| **GET** | A page |\n| <script>alert(1)</script> | [unsafe](javascript:alert%281%29) |\n");
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Method</th>', $html);
        self::assertStringContainsString('<strong>GET</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }
}
