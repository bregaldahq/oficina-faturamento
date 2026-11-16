<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Os testes rodam contra SQLite com tests/Support/schema.sqlite.sql. Se a migration ganhar uma
 * coluna que o schema dos testes não tem, o repositório passa aqui e quebra em produção: este
 * teste trava as duas listas.
 */
class SchemaTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function mysqlColumns(): array
    {
        $sql    = (string)file_get_contents(__DIR__ . '/../../migrations/001_faturamento.sql');
        $tables = [];
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\) ENGINE/s', $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $table, $body]) {
            preg_match_all('/^\s{4}(\w+)\s+(?:VARCHAR|INT|DECIMAL|DATETIME|TEXT)/m', $body, $cols);
            $tables[$table] = $cols[1];
        }

        return $tables;
    }

    /** @return array<string, list<string>> */
    private function sqliteColumns(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec((string)file_get_contents(__DIR__ . '/../Support/schema.sqlite.sql'));

        $tables = [];
        $query  = static function (string $sql, int $mode) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll($mode);
        };
        foreach ($query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name", PDO::FETCH_COLUMN) as $table) {
            $tables[(string)$table] = array_map(
                static fn (array $c): string => (string)$c['name'],
                $query("PRAGMA table_info({$table})", PDO::FETCH_ASSOC),
            );
        }

        return $tables;
    }

    public function testMesmasTabelasEColunasNaMigrationENoSchemaDosTestes(): void
    {
        $mysql  = $this->mysqlColumns();
        $sqlite = $this->sqliteColumns();
        ksort($mysql);

        $this->assertCount(7, $mysql);
        $this->assertSame(array_keys($mysql), array_keys($sqlite));
        foreach ($mysql as $table => $columns) {
            $this->assertSame($columns, $sqlite[$table], "colunas de {$table}");
        }
    }

    public function testMigrationsSemComandoProibido(): void
    {
        foreach (glob(__DIR__ . '/../../migrations/*.sql') ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(CREATE DATABASE|USE |DELIMITER)/im', (string)file_get_contents($file), basename($file));
        }
        $this->assertStringEndsWith('_demo.sql', (string)basename((string)(glob(__DIR__ . '/../../migrations/002_*.sql') ?: [''])[0]));
    }
}
