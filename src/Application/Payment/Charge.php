<?php

declare(strict_types=1);

namespace App\Application\Payment;

/** A cobrança criada no meio de pagamento: o id dela lá e o link que o cliente abre. */
final class Charge
{
    public function __construct(
        public readonly string $preferenceId,
        public readonly string $paymentUrl,
    ) {
    }
}
