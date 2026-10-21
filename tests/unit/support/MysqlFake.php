<?php
class FakeMysqli extends mysqli
{
    /** @var string[] semua SQL lewat query() / prepare() */
    public array $sqls = [];

    /** @var string[] prepared statement yang dikembalikan (pop per panggilan) */
    public array $stmtQueue = [];

    /** @var mysqli_result|false|null*/
    public $queryResult = null;

    /** @var array<int, mysqli_result|false> hasil berurutan untuk query() */
    public array $queryResults = [];

    /** @var bool */
    public bool $prepareFails = false;

    /** @var FakeMysqliStmt[] */
    public array $createdStmts = [];

    public ?FakeMysqliResult $windowResult = null;

    public function __construct()
    {

    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->sqls[] = $query;
        if ($this->queryResult !== null) {
            return $this->queryResult;
        }
        if ($this->queryResults !== []) {
            return array_shift($this->queryResults);
        }
        return false;
    }

    /** @var (callable(string): (FakeMysqliStmt|false|null))|null*/
    public $onPrepare = null;

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->sqls[] = $query;
        if ($this->prepareFails) {
            return false;
        }
        if ($this->onPrepare !== null) {
            $stmt = ($this->onPrepare)($query);
            if ($stmt instanceof FakeMysqliStmt) {
                $stmt->sql = $query;
                $this->createdStmts[] = $stmt;
                return $stmt;
            }
            if ($stmt === false) {
                return false;
            }
        }
        if ($this->stmtQueue !== []) {
            $stmt = array_shift($this->stmtQueue);
            if ($stmt instanceof FakeMysqliStmt) {
                $stmt->sql = $query;
                if ($stmt->result === null && $this->windowResult !== null) {
                    $stmt->result = $this->windowResult;
                }
                $this->createdStmts[] = $stmt;
            }
            return $stmt;
        }
        $stmt = new FakeMysqliStmt();
        $stmt->sql = $query;
        if ($this->windowResult !== null) {
            $stmt->result = $this->windowResult;
        }
        $this->createdStmts[] = $stmt;
        return $stmt;
    }

    public function countSqlContaining(string $needle): int
    {
        $n = 0;
        foreach ($this->sqls as $sql) {
            if (stripos($sql, $needle) !== false) {
                $n++;
            }
        }
        return $n;
    }
}

class FakeMysqliStmt extends mysqli_stmt
{
    public string $sql = '';

    /** @var array<int, array{types:string, vars:array}> */
    public array $binds = [];

    public bool $executeResult = true;

    /**
     * @var FakeMysqliResult|mysqli_result|null
     */
    public $result = null;

    /** @var array<int, FakeMysqliResult|mysqli_result> */
    public array $resultQueue = [];

    public int $affectedRows = 0;

    public int $closeCount = 0;

    public function __construct()
    {}

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $copy = [];
        foreach ($vars as $v) {
            $copy[] = $v;
        }
        $this->binds[] = ['types' => $types, 'vars' => $copy];
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return $this->executeResult;
    }

    public function get_result(): mysqli_result
    {
        if ($this->resultQueue !== []) {
            $next = array_shift($this->resultQueue);
            $this->result = $next;
            return $next;
        }
        if ($this->result !== null) {
            return $this->result;
        }
        return new FakeMysqliResult([]);
    }

    public function close()
    {
        $this->closeCount++;
        return true;
    }

    public function allBindVars(): array
    {
        $out = [];
        foreach ($this->binds as $b) {
            foreach ($b['vars'] as $v) {
                $out[] = $v;
            }
        }
        return $out;
    }
}

class FakeMysqliResult extends mysqli_result
{
    /** @var array<int, array<string,mixed>> */
    private array $rows;

    private int $pos = 0;

    public int $freeCount = 0;

    public function __construct(array $rows = [])
    {
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(int $mode = MYSQLI_BOTH): array|false|null
    {
        return $this->rows[$this->pos++] ?? null;
    }

    public function fetch_row(int $mode = MYSQLI_NUM): array|false|null
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    public function fetch_all(int $mode = MYSQLI_NUM): array
    {
        $out = [];
        while (($r = $this->fetch_assoc()) !== null) {
            $out[] = $mode === MYSQLI_ASSOC ? $r : array_values($r);
        }
        return $out;
    }

    public function num_rows(): int|string
    {
        return count($this->rows);
    }

    public function free(): void
    {
        $this->freeCount++;
        $this->rows = [];
    }

    public function close(): void
    {
        $this->free();
    }
}

function fake_result_of_ids(array $ids): FakeMysqliResult
{
    $rows = [];
    foreach ($ids as $id) {
        $rows[] = ['id' => (string)$id];
    }
    return new FakeMysqliResult($rows);
}
