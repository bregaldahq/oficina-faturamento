<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Context;

use App\Infrastructure\Context\RequestContext;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    public function testComecaVazio(): void
    {
        $context = new RequestContext();

        $this->assertNull($context->getCorrelationId());
        $this->assertNull($context->getActor());
        $this->assertNull($context->getRole());
    }

    public function testGuardaCorrelationIdEClaims(): void
    {
        $context = new RequestContext();
        $context->setCorrelationId('abc');
        $context->setClaims(['sub' => 'u1', 'role' => 'admin']);

        $this->assertSame('abc', $context->getCorrelationId());
        $this->assertSame('u1', $context->getActor());
        $this->assertSame('admin', $context->getRole());
    }
}
