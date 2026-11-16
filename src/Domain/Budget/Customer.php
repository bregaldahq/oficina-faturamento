<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/** O cliente como o orçamento precisa dele: vem no comando da saga, não de outro banco. */
final class Customer
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
    ) {
    }

    /** @return array{id: string, name: string, email: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }
}
