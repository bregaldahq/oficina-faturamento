<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Middleware;

use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Security\JwtProvider;
use App\Presentation\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;

class AuthMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testTokenValidoDevolveAsClaimsEPreencheOContexto(): void
    {
        $provider = new JwtProvider('segredo-de-teste', 3600);
        $context  = new RequestContext();
        $token    = $provider->generate(['sub' => 'cliente-1', 'role' => 'customer']);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $claims = (new AuthMiddleware($provider, $context))->handle();

        $this->assertSame('cliente-1', $claims['sub']);
        $this->assertSame('customer', $claims['role']);
        $this->assertSame('cliente-1', $context->getActor());
        $this->assertSame('customer', $context->getRole());
    }
}
