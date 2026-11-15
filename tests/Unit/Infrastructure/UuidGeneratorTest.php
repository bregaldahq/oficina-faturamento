<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\UuidGenerator;
use PHPUnit\Framework\TestCase;

class UuidGeneratorTest extends TestCase
{
    public function testGeraUuidV4Distintos(): void
    {
        $generator = new UuidGenerator();
        $a         = $generator->generate();
        $b         = $generator->generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $a,
        );
        $this->assertNotSame($a, $b);
    }
}
