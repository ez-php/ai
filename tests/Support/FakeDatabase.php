<?php

declare(strict_types=1);

namespace Tests\Ai\Support;

use EzPhp\Contracts\DatabaseInterface;
use LogicException;
use PDO;

/**
 * Configurable in-memory stub for DatabaseInterface.
 *
 * Pre-program query results via addQueryResult(). Records all execute() calls
 * for later assertion via getExecuted().
 *
 * @package Tests\Ai\Support
 */
final class FakeDatabase implements DatabaseInterface
{
    /** @var list<list<array<string, mixed>>> */
    private array $queryResults = [];

    /** @var list<array{sql: string, bindings: array<int|string, mixed>}> */
    private array $executed = [];

    /**
     * Queue a result to be returned by the next query() call.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return void
     */
    public function addQueryResult(array $rows): void
    {
        $this->queryResults[] = $rows;
    }

    /**
     * @return list<array{sql: string, bindings: array<int|string, mixed>}>
     */
    public function getExecuted(): array
    {
        return $this->executed;
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $sql, array $bindings = []): array
    {
        return array_shift($this->queryResults) ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $this->executed[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function transaction(callable $fn): mixed
    {
        return $fn();
    }

    /**
     * {@inheritDoc}
     */
    public function getPdo(): PDO
    {
        throw new LogicException('FakeDatabase does not provide a real PDO connection.');
    }
}
