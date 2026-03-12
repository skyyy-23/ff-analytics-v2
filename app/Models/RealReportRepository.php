<?php
class RealReportRepository implements BranchRepositoryInterface
{
    private $pdo;
    private $table;
    private $columns;
    private $selectSql;
    private $findSql;

    public function __construct(PDO $pdo, string $table = 'branch_reports', array $columns = [])
    {
        $this->pdo = $pdo;
        $this->table = trim($table);
        if ($this->table === '') {
            throw new InvalidArgumentException('DB branch table name is required.');
        }

        $defaults = [
            'branch' => 'branch',
            'current_sales' => 'current_sales',
            'previous_sales' => 'previous_sales',
            'expenses' => 'expenses',
            'cogs' => 'cogs',
            'avg_inventory' => 'avg_inventory',
            'dead_stock' => 'dead_stock',
            'expected_pos_days' => 'expected_pos_days',
            'actual_pos_days' => 'actual_pos_days',
        ];

        $this->columns = array_merge($defaults, $columns);
        $this->buildSql();
    }

    public static function fromEnv(string $basePath): self
    {
        Env::load($basePath);

        $host = self::env('DB_HOST', '');
        $port = self::env('DB_PORT', '3306');
        $name = self::env('DB_NAME', '');
        $user = self::env('DB_USER', '');
        $pass = self::env('DB_PASS', '');
        $charset = self::env('DB_CHARSET', 'utf8mb4');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException(
                'Missing database config. Set DB_HOST, DB_NAME, DB_USER (and optional DB_PASS).'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port !== '' ? $port : '3306',
            $name,
            $charset !== '' ? $charset : 'utf8mb4'
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $table = self::env('DB_BRANCH_TABLE', 'branch_reports');
        $columns = [
            'branch' => self::env('DB_COL_BRANCH', 'branch'),
            'current_sales' => self::env('DB_COL_CURRENT_SALES', 'current_sales'),
            'previous_sales' => self::env('DB_COL_PREVIOUS_SALES', 'previous_sales'),
            'expenses' => self::env('DB_COL_EXPENSES', 'expenses'),
            'cogs' => self::env('DB_COL_COGS', 'cogs'),
            'avg_inventory' => self::env('DB_COL_AVG_INVENTORY', 'avg_inventory'),
            'dead_stock' => self::env('DB_COL_DEAD_STOCK', 'dead_stock'),
            'expected_pos_days' => self::env('DB_COL_EXPECTED_POS_DAYS', 'expected_pos_days'),
            'actual_pos_days' => self::env('DB_COL_ACTUAL_POS_DAYS', 'actual_pos_days'),
        ];

        return new self($pdo, $table, $columns);
    }

    public function getBranches(): array
    {
        $rows = [];

        try {
            $reportingColumn = self::env('DB_COL_REPORTING_PERIOD', 'reporting_period');
            $latestSql = $this->buildLatestMonthSql($reportingColumn);
            $stmt = $this->pdo->query($latestSql);
            if ($stmt) {
                $rows = $stmt->fetchAll();
            }
        } catch (Throwable $e) {
            $rows = [];
        }

        if (!is_array($rows) || !$rows) {
            $stmt = $this->pdo->query($this->selectSql);
            if (!$stmt) {
                return [];
            }

            $rows = $stmt->fetchAll();
            if (!is_array($rows) || !$rows) {
                return [];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeBranchRow($row);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    public function findBranch(string $branchName): ?array
    {
        $branchName = trim($branchName);
        if ($branchName === '') {
            return null;
        }

        $row = null;

        try {
            $latestSql = $this->buildFindLatestSql(self::env('DB_COL_REPORTING_PERIOD', 'reporting_period'));
            $stmt = $this->pdo->prepare($latestSql);
            $stmt->execute(['branch_name' => $branchName]);
            $fetched = $stmt->fetch();
            if (is_array($fetched)) {
                $row = $fetched;
            }
        } catch (Throwable $e) {
            $row = null;
        }

        if (!is_array($row)) {
            $stmt = $this->pdo->prepare($this->findSql);
            $stmt->execute(['branch_name' => $branchName]);
            $fetched = $stmt->fetch();
            if (!is_array($fetched)) {
                return null;
            }
            $row = $fetched;
        }

        return $this->normalizeBranchRow($row);
    }

    public function getMonthlyReports(?string $branchName = null, ?int $monthLimit = null): array
    {
        $reportingColumn = self::env('DB_COL_REPORTING_PERIOD', 'reporting_period');
        $normalizedBranchName = trim((string)$branchName);
        if ($normalizedBranchName === '') {
            $normalizedBranchName = null;
        }
        $normalizedMonthLimit = $this->normalizeMonthLimit($monthLimit);

        try {
            [$sql, $params] = $this->buildMonthlySql($reportingColumn, $normalizedBranchName, $normalizedMonthLimit);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $rows = $stmt->fetchAll();
            if (!is_array($rows) || !$rows) {
                return [];
            }

            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $normalized = $this->normalizeBranchRow($row);
                if ($normalized !== null) {
                    $result[] = $normalized;
                }
            }

            return $result;
        } catch (Throwable $e) {
            $stmt = $this->pdo->query($this->selectSql);
            if (!$stmt) {
                return [];
            }

            $fallback = $stmt->fetchAll();
            if (!is_array($fallback) || !$fallback) {
                return [];
            }

            $result = [];
            foreach ($fallback as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branch['reporting_period'] = $branch['reporting_period'] ?? null;
                $result[] = $branch;
            }
            return $result;
        }
    }

    private function buildSql(): void
    {
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $orderBy = $branchCol;

        $mappedColumns = [];
        foreach ($this->columns as $alias => $columnName) {
            $mappedColumns[] = sprintf(
                '%s AS `%s`',
                $this->quoteIdentifier($columnName),
                $alias
            );
        }

        $selectCols = implode(', ', $mappedColumns);
        $this->selectSql = sprintf(
            'SELECT %s FROM %s ORDER BY %s ASC',
            $selectCols,
            $table,
            $orderBy
        );

        $this->findSql = sprintf(
            'SELECT %s FROM %s WHERE %s = :branch_name LIMIT 1',
            $selectCols,
            $table,
            $branchCol
        );
    }

    private function buildMonthlySql(string $reportingColumn, ?string $branchName, int $monthLimit): array
    {
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($reportingColumn);

        $mappedColumns = [];
        foreach ($this->columns as $alias => $columnName) {
            $mappedColumns[] = sprintf(
                't.%s AS `%s`',
                $this->quoteIdentifier($columnName),
                $alias
            );
        }
        $selectCols = implode(', ', $mappedColumns);

        $sql = sprintf(
            'SELECT %s, t.%s AS `reporting_period` FROM %s t ' .
            'INNER JOIN (' .
            'SELECT DISTINCT %s AS `window_period` FROM %s ORDER BY %s DESC LIMIT %d' .
            ') month_window ON t.%s = month_window.`window_period`',
            $selectCols,
            $reportingCol,
            $table,
            $reportingCol,
            $table,
            $reportingCol,
            $monthLimit,
            $reportingCol
        );

        $params = [];
        if ($branchName !== null) {
            $sql .= sprintf(' WHERE t.%s = :branch_name', $branchCol);
            $params['branch_name'] = $branchName;
        }

        $sql .= sprintf(' ORDER BY t.%s DESC, t.%s ASC', $reportingCol, $branchCol);

        return [$sql, $params];
    }

    private function buildLatestMonthSql(string $reportingColumn): string
    {
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($reportingColumn);

        $mappedColumns = [];
        foreach ($this->columns as $alias => $columnName) {
            $mappedColumns[] = sprintf(
                '%s AS `%s`',
                $this->quoteIdentifier($columnName),
                $alias
            );
        }
        $selectCols = implode(', ', $mappedColumns);

        return sprintf(
            'SELECT %s, %s AS `reporting_period` FROM %s WHERE %s = (SELECT MAX(%s) FROM %s) ORDER BY %s ASC',
            $selectCols,
            $reportingCol,
            $table,
            $reportingCol,
            $reportingCol,
            $table,
            $branchCol
        );
    }

    private function buildFindLatestSql(string $reportingColumn): string
    {
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($reportingColumn);

        $mappedColumns = [];
        foreach ($this->columns as $alias => $columnName) {
            $mappedColumns[] = sprintf(
                '%s AS `%s`',
                $this->quoteIdentifier($columnName),
                $alias
            );
        }
        $selectCols = implode(', ', $mappedColumns);

        return sprintf(
            'SELECT %s, %s AS `reporting_period` FROM %s WHERE %s = :branch_name ORDER BY %s DESC LIMIT 1',
            $selectCols,
            $reportingCol,
            $table,
            $branchCol,
            $reportingCol
        );
    }

    private function normalizeMonthLimit(?int $monthLimit): int
    {
        if ($monthLimit === null || $monthLimit <= 0) {
            $monthLimit = (int)self::env('DB_HISTORY_MONTH_LIMIT', '12');
        }

        if ($monthLimit <= 0) {
            $monthLimit = 12;
        }

        return min($monthLimit, 60);
    }

    private function normalizeBranchRow(array $row): ?array
    {
        $branch = trim((string)($row['branch'] ?? ''));
        if ($branch === '') {
            return null;
        }

        $currentSales = $this->toFloat($row['current_sales'] ?? null);
        $previousSales = $this->toFloat($row['previous_sales'] ?? null);
        $expenses = $this->toFloat($row['expenses'] ?? null);
        $cogs = $this->toFloat($row['cogs'] ?? null);
        $avgInventory = $this->toFloat($row['avg_inventory'] ?? null);
        $deadStock = $this->toFloat($row['dead_stock'] ?? null);
        $expectedPosDays = $this->toInt($row['expected_pos_days'] ?? null);
        $actualPosDays = $this->toInt($row['actual_pos_days'] ?? null);

        if (
            $currentSales === null ||
            $previousSales === null ||
            $expenses === null ||
            $cogs === null ||
            $avgInventory === null ||
            $deadStock === null ||
            $expectedPosDays === null ||
            $actualPosDays === null
        ) {
            return null;
        }

        $normalized = [
            'branch' => $branch,
            'current_sales' => $currentSales,
            'previous_sales' => $previousSales,
            'expenses' => $expenses,
            'cogs' => $cogs,
            'avg_inventory' => $avgInventory,
            'dead_stock' => $deadStock,
            'expected_pos_days' => $expectedPosDays,
            'actual_pos_days' => $actualPosDays,
        ];

        $reportingPeriod = $this->normalizeReportingPeriod($row['reporting_period'] ?? null);
        if ($reportingPeriod !== null) {
            $normalized['reporting_period'] = $reportingPeriod;
        }

        return $normalized;
    }

    private function toFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return null;
        }

        $clean = trim(str_replace(',', '', $value));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float)$clean;
    }

    private function toInt($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int)round($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (int)round((float)$clean);
    }

    private function quoteCompositeIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        $quotedParts = [];
        foreach ($parts as $part) {
            $quotedParts[] = $this->quoteIdentifier($part);
        }
        return implode('.', $quotedParts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $id = trim($identifier);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
        return '`' . $id . '`';
    }

    private function normalizeReportingPeriod($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        $timestamp = strtotime($clean);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        return $default;
    }
}
