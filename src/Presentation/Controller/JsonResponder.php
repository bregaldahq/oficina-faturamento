<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;

/** Resposta JSON e o mapeamento das exceções de domínio para códigos HTTP, igual em todo controller. */
trait JsonResponder
{
    /** @param callable(): (array<mixed>|null) $action */
    private function respond(callable $action, int $success = 200): void
    {
        try {
            $data = $action();
            http_response_code($data === null ? 204 : $success);
            if ($data !== null) {
                echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } catch (NotFoundException $e) {
            $this->error(404, $e->getMessage());
        } catch (ConcurrencyException) {
            $this->error(409, 'O registro foi alterado por outra operação. Tente de novo.');
        } catch (DomainException $e) {
            $this->error(422, $e->getMessage());
        }
    }

    private function error(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $body */
    private function requireString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException("Informe '{$field}'.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $body */
    private function requireNumber(array $body, string $field): float
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new DomainException("Informe '{$field}' como número.");
        }

        return (float)$value;
    }

    /** @param array<string, mixed> $body */
    private function requireInt(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value)) {
            throw new DomainException("Informe '{$field}' como número inteiro.");
        }

        return $value;
    }
}
