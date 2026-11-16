<?php

declare(strict_types=1);

namespace App\Domain\Exception;

class InvalidBudgetTransitionException extends DomainException
{
    public static function from(string $status, string $action): self
    {
        return new self("O orçamento está em {$status} e não aceita a ação '{$action}'.");
    }
}
