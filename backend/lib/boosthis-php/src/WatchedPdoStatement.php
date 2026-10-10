<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Installed only on a PDO handle explicitly handed to Boosthis. PDO constructs
 * this class itself; no connection string or database metadata is retained.
 */
final class WatchedPdoStatement extends \PDOStatement
{
    /** @var array{generation:int,call:int,identity:int,start:float}|null */
    private ?array $boosthisToken = null;
    private int $boosthisRows = 0;
    private bool $boosthisImplicitQuery = false;
    private bool $boosthisCompleted = false;
    private ?float $boosthisDrainStartedAt = null;

    protected function __construct()
    {
        // For PDO::query(), construction happens after the round trip. A
        // positive column count distinguishes that executed result from a
        // merely prepared statement without inspecting SQL. Count it now as
        // watched-but-round-trip-untimed; fetch methods add observable drain.
        try {
            if ($this->columnCount() > 0) {
                $this->boosthisToken = DbWork::issue($this->queryString, []);
                if ($this->boosthisToken !== null) {
                    DbWork::complete($this->boosthisToken, $this->boosthisToken['start']);
                }
                $this->boosthisImplicitQuery = true;
                $this->boosthisCompleted = true;
            }
        } catch (\Throwable) {
            // Instrumentation must not make statement construction fail.
        }
    }

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null)
    {
        $token = DbWork::issue($this->queryString, $params ?? []);
        try {
            return parent::execute($params);
        } finally {
            DbWork::complete($token);
            $this->boosthisToken = $token;
            $this->boosthisRows = 0;
            $this->boosthisImplicitQuery = false;
            $this->boosthisCompleted = true;
            $this->boosthisDrainStartedAt = null;
        }
    }

    #[\ReturnTypeWillChange]
    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
    {
        $this->beginImplicitQueryDrain();
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($row === false) {
            $this->finishImplicitQueryDrain();
            DbWork::rows($this->boosthisToken, $this->boosthisRows);
        } else {
            $this->boosthisRows++;
        }
        return $row;
    }

    #[\ReturnTypeWillChange]
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args)
    {
        $this->beginImplicitQueryDrain();
        $rows = parent::fetchAll($mode, ...$args);
        $this->boosthisRows += count($rows);
        $this->finishImplicitQueryDrain();
        DbWork::rows($this->boosthisToken, $this->boosthisRows);
        return $rows;
    }

    #[\ReturnTypeWillChange]
    public function closeCursor()
    {
        try {
            return parent::closeCursor();
        } finally {
            $this->finishImplicitQueryDrain();
        }
    }

    /**
     * PDO::query() does use ATTR_STATEMENT_CLASS, but constructs the statement
     * only after the server round trip has happened; PHP exposes no issue hook.
     * The first drain operation therefore captures the request scope and times
     * the observable drain. It is a watched-but-untimed round trip when the
     * result is empty, and a drain-timed round trip otherwise. A query result
     * the app never touches cannot be distinguished from a prepared statement
     * that has not executed and is deliberately left as a blind spot.
     */
    private function beginImplicitQueryDrain(): void
    {
        if ($this->boosthisToken !== null) {
            if ($this->boosthisImplicitQuery && $this->boosthisDrainStartedAt === null) {
                $this->boosthisDrainStartedAt = microtime(true) * 1000;
            }
            return;
        }
        $this->boosthisToken = DbWork::issue($this->queryString, []);
        $this->boosthisRows = 0;
        $this->boosthisImplicitQuery = true;
        $this->boosthisCompleted = false;
        $this->boosthisDrainStartedAt = microtime(true) * 1000;
    }

    private function finishImplicitQueryDrain(): void
    {
        if (!$this->boosthisImplicitQuery || $this->boosthisCompleted) {
            if ($this->boosthisImplicitQuery && $this->boosthisDrainStartedAt !== null) {
                DbWork::addDrain(
                    $this->boosthisToken,
                    microtime(true) * 1000 - $this->boosthisDrainStartedAt
                );
                $this->boosthisDrainStartedAt = null;
            }
            return;
        }
        DbWork::complete($this->boosthisToken);
        $this->boosthisCompleted = true;
        if ($this->boosthisDrainStartedAt !== null) {
            DbWork::addDrain(
                $this->boosthisToken,
                microtime(true) * 1000 - $this->boosthisDrainStartedAt
            );
            $this->boosthisDrainStartedAt = null;
        }
    }
}