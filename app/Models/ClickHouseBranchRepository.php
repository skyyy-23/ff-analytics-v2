<?php
require_once __DIR__ . '/BranchRepositoryInterface.php';
require_once __DIR__ . '/../../services/Env.php';

class ClickHouseBranchRepository implements BranchRepositoryInterface
{
    private $baseUrl;
    private $database;
    private $user;
    private $password;
    private $table;
    private $columns;
    private $reportingColumn;
    private $sourceMode;
    private $timeoutMs;
    private $connectTimeoutMs;
    private $branches;
    private $branchesByName;

    public function __construct()
    {
        Env::load(__DIR__ . '/../..');

        $this->baseUrl = rtrim($this->readEnv('CLICKHOUSE_URL', ''), '/');
        $this->database = trim($this->readEnv('CLICKHOUSE_DATABASE', ''));
        $this->user = $this->readEnv('CLICKHOUSE_USER', 'default');
        $this->password = $this->readEnv('CLICKHOUSE_PASSWORD', '');
        $this->table = $this->normalizeCompositeIdentifier(
            $this->readEnv('CLICKHOUSE_TABLE', 'branch_reports'),
            'branch_reports'
        );
        $this->reportingColumn = $this->normalizeIdentifier(
            $this->readEnv('CLICKHOUSE_COL_REPORTING_PERIOD', 'reporting_period'),
            'reporting_period'
        );
        $this->sourceMode = strtolower(trim($this->readEnv('CLICKHOUSE_SOURCE_MODE', 'table')));
        if ($this->sourceMode === '') {
            $this->sourceMode = 'table';
        }

        $this->columns = [
            'branch' => $this->normalizeIdentifier($this->readEnv('CLICKHOUSE_COL_BRANCH', 'branch'), 'branch'),
            'current_sales' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_CURRENT_SALES', 'current_sales'),
                'current_sales'
            ),
            'previous_sales' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_PREVIOUS_SALES', 'previous_sales'),
                'previous_sales'
            ),
            'expenses' => $this->normalizeIdentifier($this->readEnv('CLICKHOUSE_COL_EXPENSES', 'expenses'), 'expenses'),
            'cogs' => $this->normalizeIdentifier($this->readEnv('CLICKHOUSE_COL_COGS', 'cogs'), 'cogs'),
            'avg_inventory' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_AVG_INVENTORY', 'avg_inventory'),
                'avg_inventory'
            ),
            'dead_stock' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_DEAD_STOCK', 'dead_stock'),
                'dead_stock'
            ),
            'expected_pos_days' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_EXPECTED_POS_DAYS', 'expected_pos_days'),
                'expected_pos_days'
            ),
            'actual_pos_days' => $this->normalizeIdentifier(
                $this->readEnv('CLICKHOUSE_COL_ACTUAL_POS_DAYS', 'actual_pos_days'),
                'actual_pos_days'
            ),
        ];

        $this->timeoutMs = $this->normalizeTimeoutMs($this->readEnv('CLICKHOUSE_TIMEOUT_MS', '8000'), 8000);
        $this->connectTimeoutMs = $this->normalizeTimeoutMs(
            $this->readEnv('CLICKHOUSE_CONNECT_TIMEOUT_MS', '3000'),
            3000
        );
        $this->branches = null;
        $this->branchesByName = null;
    }

    public function getBranches(): array
    {
        if ($this->useDerivedSource()) {
            $this->loadBranchesFromDerivedSource();
            return $this->branches;
        }

        $this->loadBranchesFromTable();
        return $this->branches;
    }

    public function findBranch(string $branchName): ?array
    {
        $cleanBranchName = trim($branchName);
        if ($cleanBranchName === '') {
            return null;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        if ($this->useDerivedSource()) {
            $rows = $this->fetchDerivedRows($cleanBranchName, 1);
            return !empty($rows) ? $rows[0] : null;
        }

        $selectCols = $this->buildSelectColumns();
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($this->reportingColumn);
        $branchLiteral = $this->quoteStringLiteral($cleanBranchName);

        $sql = sprintf(
            'SELECT %s, %s AS %s FROM %s WHERE lowerUTF8(trimBoth(%s)) = lowerUTF8(%s) ORDER BY %s DESC LIMIT 1',
            $selectCols,
            $reportingCol,
            $this->quoteIdentifier('reporting_period'),
            $table,
            $branchCol,
            $branchLiteral,
            $reportingCol
        );

        $rows = $this->runQuery($sql);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $this->normalizeBranchRow($rows[0]);
    }

    public function getMonthlyReports(?string $branchName = null, ?int $monthLimit = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $limit = $this->normalizeMonthLimit($monthLimit);

        if ($this->useDerivedSource()) {
            return $this->fetchDerivedRows($branchName, $limit);
        }

        $selectCols = $this->buildSelectColumns();
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($this->reportingColumn);
        $branchFilter = '';

        $normalizedBranch = trim((string)$branchName);
        if ($normalizedBranch !== '') {
            $branchFilter = sprintf(
                'lowerUTF8(trimBoth(%s)) = lowerUTF8(%s)',
                $branchCol,
                $this->quoteStringLiteral($normalizedBranch)
            );
        }

        $subWhere = $branchFilter !== '' ? ' WHERE ' . $branchFilter : '';
        $outerAnd = $branchFilter !== '' ? ' AND ' . $branchFilter : '';

        $sql = sprintf(
            'SELECT %s, %s AS %s FROM %s WHERE %s IN (' .
            'SELECT DISTINCT %s FROM %s%s ORDER BY %s DESC LIMIT %d' .
            ')%s ORDER BY %s DESC, %s ASC',
            $selectCols,
            $reportingCol,
            $this->quoteIdentifier('reporting_period'),
            $table,
            $reportingCol,
            $reportingCol,
            $table,
            $subWhere,
            $reportingCol,
            $limit,
            $outerAnd,
            $reportingCol,
            $branchCol
        );

        $rows = $this->runQuery($sql);
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
    }

    private function loadBranchesFromDerivedSource(): void
    {
        if ($this->branches !== null) {
            return;
        }

        $this->branches = [];
        $this->branchesByName = [];

        if (!$this->isConfigured()) {
            return;
        }

        $rows = $this->fetchDerivedRows(null, 1);
        foreach ($rows as $row) {
            $this->branches[] = $row;
            $this->branchesByName[$row['branch']] = $row;
        }
    }

    private function fetchDerivedRows(?string $branchName, int $monthLimit): array
    {
        $sql = $this->buildDerivedMonthlySql($branchName, $monthLimit);
        $rows = $this->runQuery($sql);
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
    }

    private function buildDerivedMonthlySql(?string $branchName, int $monthLimit): string
    {
        $limit = max(1, min((int)$monthLimit, 60));
        $cleanBranchName = trim((string)$branchName);
        $monthWindowBranchWhere = '';
        $outerBranchFilter = '';

        if ($cleanBranchName !== '') {
            $branchExpr = 'lowerUTF8(' . $this->quoteStringLiteral($cleanBranchName) . ')';
            $monthWindowBranchWhere = 'WHERE branch_key = ' . $branchExpr;
            $outerBranchFilter = ' AND s.branch_key = ' . $branchExpr;
        }

        $salesSummaryTable = $this->quoteCompositeIdentifier('dw_sales_summary');
        $cashierTable = $this->quoteCompositeIdentifier('dw_admin_cashier_report');
        $salesReportTable = $this->quoteCompositeIdentifier('dw_sales_report');
        $inventoryCtes = '';
        $inventoryJoin = '';
        $avgInventorySelect = 'toFloat64(0) AS avg_inventory, ';
        $deadStockSelect = 'toFloat64(0) AS dead_stock, ';

        if ($this->useDerivedInventoryProxy()) {
            $inventoryDetailsTable = $this->quoteCompositeIdentifier(
                $this->normalizeCompositeIdentifier(
                    $this->readEnv('CLICKHOUSE_DERIVED_INVENTORY_DETAILS_TABLE', 'tbl_branch_inventory_details'),
                    'tbl_branch_inventory_details'
                )
            );
            $branchLookupTable = $this->quoteCompositeIdentifier(
                $this->normalizeCompositeIdentifier(
                    $this->readEnv('CLICKHOUSE_DERIVED_BRANCH_LOOKUP_TABLE', 'dw_branch_purchase_summary'),
                    'dw_branch_purchase_summary'
                )
            );
            $priceCap = sprintf('%.4F', $this->resolveDerivedInventoryProxyPriceCap());
            $deadStockMode = $this->resolveDerivedDeadStockMode();
            $deadStockExpr = 'toFloat64(subtract_value)';
            if ($deadStockMode === 'net_add') {
                $deadStockExpr = sprintf(
                    'toFloat64(greatest(add_value - subtract_value, 0.0) * %.6F)',
                    $this->resolveDerivedDeadStockFactor()
                );
            }

            $inventoryCtes = sprintf(
                'inventory_branch_map AS (' .
                'SELECT toUInt32(branch_id) AS branch_id, lowerUTF8(trimBoth(any(branch_name))) AS branch_key ' .
                'FROM %s ' .
                'WHERE branch_name != \'\' ' .
                'GROUP BY branch_id' .
                '), ' .
                'inventory_base_by_month AS (' .
                'SELECT ' .
                'ibm.branch_key AS branch_key, ' .
                'toStartOfMonth(toDate(ibd.date_created)) AS month_start, ' .
                'sumIf(toFloat64OrZero(toString(ibd.total_price)), upperUTF8(ibd.inventory_type) = \'ADD\') AS add_value, ' .
                'sumIf(toFloat64OrZero(toString(ibd.total_price)), upperUTF8(ibd.inventory_type) = \'SUBTRACT\') AS subtract_value ' .
                'FROM %s ibd ' .
                'INNER JOIN inventory_branch_map ibm ON ibm.branch_id = toUInt32(ibd.branch_id) ' .
                'WHERE ibd.status = 1 ' .
                'AND ibd.remove = 0 ' .
                'AND ibd.date_created IS NOT NULL ' .
                'AND toFloat64OrZero(toString(ibd.total_price)) >= 0 ' .
                'AND toFloat64OrZero(toString(ibd.total_price)) <= %s ' .
                'GROUP BY branch_key, month_start' .
                '), ' .
                'inventory_by_month AS (' .
                'SELECT ' .
                'branch_key, ' .
                'month_start, ' .
                'toFloat64((add_value + subtract_value) / 2) AS avg_inventory, ' .
                '%s AS dead_stock ' .
                'FROM inventory_base_by_month' .
                '), ',
                $branchLookupTable,
                $inventoryDetailsTable,
                $priceCap,
                $deadStockExpr
            );
            $inventoryJoin = 'LEFT JOIN inventory_by_month i ON i.branch_key = s.branch_key AND i.month_start = s.month_start ';
            $avgInventorySelect = 'toFloat64(ifNull(i.avg_inventory, 0.0)) AS avg_inventory, ';
            $deadStockSelect = 'toFloat64(ifNull(i.dead_stock, 0.0)) AS dead_stock, ';
        }

        return sprintf(
            'WITH ' .
            // Prefer dw_sales_summary monthly values, but fallback to dw_sales_report
            // when summary data is delayed for newer months.
            'sales_summary_by_month AS (' .
            'SELECT ' .
            'lowerUTF8(trimBoth(branch_name)) AS branch_key, ' .
            'argMax(branch_name, report_date) AS branch_label, ' .
            'toStartOfMonth(report_date) AS month_start, ' .
            'sum(toFloat64OrZero(toString(grand_total))) AS current_sales, ' .
            'countDistinct(report_date) AS actual_pos_days ' .
            'FROM %s ' .
            'WHERE branch_name != \'\' ' .
            'GROUP BY branch_key, month_start' .
            '), ' .
            'sales_report_by_month AS (' .
            'SELECT ' .
            'lowerUTF8(trimBoth(branch_name)) AS branch_key, ' .
            'argMax(branch_name, parsed_date) AS branch_label, ' .
            'toStartOfMonth(toDate(parsed_date)) AS month_start, ' .
            'sum(toFloat64OrZero(toString(total_sales))) AS current_sales, ' .
            'countDistinct(toDate(parsed_date)) AS actual_pos_days ' .
            'FROM (' .
            'SELECT branch_name, total_sales, parseDateTimeBestEffortOrNull(toString(date_created)) AS parsed_date ' .
            'FROM %s' .
            ') ' .
            'WHERE parsed_date IS NOT NULL AND branch_name != \'\' ' .
            'GROUP BY branch_key, month_start' .
            '), ' .
            'sales_by_month AS (' .
            'SELECT ' .
            'branch_key, ' .
            'argMax(branch_label, source_priority) AS branch_label, ' .
            'month_start, ' .
            'argMax(current_sales, source_priority) AS current_sales, ' .
            'argMax(actual_pos_days, source_priority) AS actual_pos_days ' .
            'FROM (' .
            'SELECT branch_key, branch_label, month_start, current_sales, actual_pos_days, toUInt8(2) AS source_priority ' .
            'FROM sales_summary_by_month ' .
            'UNION ALL ' .
            'SELECT branch_key, branch_label, month_start, current_sales, actual_pos_days, toUInt8(1) AS source_priority ' .
            'FROM sales_report_by_month' .
            ') ' .
            'GROUP BY branch_key, month_start' .
            '), ' .
            'sales_with_prev AS (' .
            'SELECT ' .
            's.branch_key, ' .
            's.branch_label, ' .
            's.month_start, ' .
            's.current_sales, ' .
            // Use strict previous calendar month; if missing, treat as no previous data.
            'ifNull(prev.current_sales, 0.0) AS previous_sales, ' .
            's.actual_pos_days ' .
            'FROM sales_by_month s ' .
            'LEFT JOIN sales_by_month prev ON prev.branch_key = s.branch_key AND prev.month_start = addMonths(s.month_start, -1)' .
            '), ' .
            'expenses_by_month AS (' .
            'SELECT ' .
            'lowerUTF8(trimBoth(branch)) AS branch_key, ' .
            'toStartOfMonth(toDate(parsed_date)) AS month_start, ' .
            'sum(toFloat64OrZero(toString(total_expenses))) AS expenses ' .
            'FROM (' .
            'SELECT branch, total_expenses, parseDateTimeBestEffortOrNull(toString(date)) AS parsed_date ' .
            'FROM %s' .
            ') ' .
            'WHERE parsed_date IS NOT NULL AND branch != \'\' ' .
            'GROUP BY branch_key, month_start' .
            '), ' .
            'cogs_by_month AS (' .
            'SELECT ' .
            'lowerUTF8(trimBoth(branch_name)) AS branch_key, ' .
            'toStartOfMonth(toDate(parsed_date)) AS month_start, ' .
            'sum(toFloat64OrZero(toString(cost_of_goods))) AS cogs ' .
            'FROM (' .
            'SELECT branch_name, cost_of_goods, parseDateTimeBestEffortOrNull(toString(date_created)) AS parsed_date ' .
            'FROM %s' .
            ') ' .
            'WHERE parsed_date IS NOT NULL AND branch_name != \'\' ' .
            'GROUP BY branch_key, month_start' .
            '), ' .
            '%s' .
            'month_window AS (' .
            'SELECT DISTINCT month_start ' .
            'FROM sales_by_month ' .
            '%s ' .
            'ORDER BY month_start DESC ' .
            'LIMIT %d' .
            ') ' .
            'SELECT ' .
            's.branch_label AS branch, ' .
            'toFloat64(s.current_sales) AS current_sales, ' .
            'toFloat64(s.previous_sales) AS previous_sales, ' .
            'toFloat64(ifNull(e.expenses, 0.0)) AS expenses, ' .
            'toFloat64(ifNull(c.cogs, 0.0)) AS cogs, ' .
            '%s' .
            '%s' .
            'toInt32(toDayOfMonth(toLastDayOfMonth(s.month_start))) AS expected_pos_days, ' .
            'toInt32(ifNull(s.actual_pos_days, 0)) AS actual_pos_days, ' .
            'toDate(s.month_start) AS reporting_period ' .
            'FROM sales_with_prev s ' .
            'INNER JOIN month_window mw ON mw.month_start = s.month_start ' .
            'LEFT JOIN expenses_by_month e ON e.branch_key = s.branch_key AND e.month_start = s.month_start ' .
            'LEFT JOIN cogs_by_month c ON c.branch_key = s.branch_key AND c.month_start = s.month_start ' .
            '%s' .
            'WHERE 1 = 1%s ' .
            'ORDER BY reporting_period DESC, branch ASC',
            $salesSummaryTable,
            $salesReportTable,
            $cashierTable,
            $salesReportTable,
            $inventoryCtes,
            $monthWindowBranchWhere,
            $limit,
            $avgInventorySelect,
            $deadStockSelect,
            $inventoryJoin,
            $outerBranchFilter
        );
    }

    private function loadBranchesFromTable(): void
    {
        if ($this->branches !== null) {
            return;
        }

        if (!$this->isConfigured()) {
            $this->branches = [];
            $this->branchesByName = [];
            return;
        }

        $selectCols = $this->buildSelectColumns();
        $table = $this->quoteCompositeIdentifier($this->table);
        $branchCol = $this->quoteIdentifier($this->columns['branch']);
        $reportingCol = $this->quoteIdentifier($this->reportingColumn);

        $sql = sprintf(
            'SELECT %s, %s AS %s FROM %s WHERE %s = (SELECT max(%s) FROM %s) ORDER BY %s ASC',
            $selectCols,
            $reportingCol,
            $this->quoteIdentifier('reporting_period'),
            $table,
            $reportingCol,
            $reportingCol,
            $table,
            $branchCol
        );

        $rows = $this->runQuery($sql);
        $this->branches = [];
        $this->branchesByName = [];

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeBranchRow($row);
            if ($normalized === null) {
                continue;
            }

            $this->branches[] = $normalized;
            $this->branchesByName[$normalized['branch']] = $normalized;
        }

        usort($this->branches, function (array $a, array $b): int {
            return strcasecmp((string)($a['branch'] ?? ''), (string)($b['branch'] ?? ''));
        });
    }

    private function buildSelectColumns(): string
    {
        $parts = [];
        foreach ($this->columns as $alias => $column) {
            $parts[] = sprintf(
                '%s AS %s',
                $this->quoteIdentifier($column),
                $this->quoteIdentifier($alias)
            );
        }

        return implode(', ', $parts);
    }

    private function runQuery(string $sql): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if (!preg_match('/^\s*SELECT\b/i', $sql) && !preg_match('/^\s*WITH\b/i', $sql)) {
            return null;
        }

        $url = $this->baseUrl . '/';
        $params = ['readonly' => '1'];
        if ($this->database !== '') {
            $params['database'] = $this->database;
        }
        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sql . ' FORMAT JSON',
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/plain; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
        ];

        if ($this->user !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->user . ':' . $this->password;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return null;
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (array_values($decoded) === $decoded) {
            return $decoded;
        }

        return null;
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

    private function normalizeMonthLimit(?int $monthLimit): int
    {
        if ($monthLimit === null || $monthLimit <= 0) {
            $monthLimit = (int)$this->readEnv('CLICKHOUSE_HISTORY_MONTH_LIMIT', '24');
        }

        if ($monthLimit <= 0) {
            $monthLimit = 24;
        }

        return min($monthLimit, 60);
    }

    private function normalizeReportingPeriod($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $clean = trim((string)$value);
        if ($clean === '') {
            return null;
        }

        $timestamp = strtotime($clean);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $id = trim($identifier);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
        return '`' . $id . '`';
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

    private function quoteStringLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function normalizeIdentifier(string $identifier, string $default): string
    {
        $candidate = trim($identifier);
        if ($candidate === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate)) {
            return $default;
        }
        return $candidate;
    }

    private function normalizeCompositeIdentifier(string $identifier, string $default): string
    {
        $candidate = trim($identifier);
        if ($candidate === '') {
            return $default;
        }

        $parts = explode('.', $candidate);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($part))) {
                return $default;
            }
        }

        return implode('.', array_map('trim', $parts));
    }

    private function normalizeTimeoutMs(string $raw, int $default): int
    {
        $value = (int)trim($raw);
        if ($value <= 0) {
            return $default;
        }
        return $value;
    }

    private function useDerivedSource(): bool
    {
        return $this->sourceMode === 'dw_derived';
    }

    private function useDerivedInventoryProxy(): bool
    {
        if (!$this->useDerivedSource()) {
            return false;
        }

        return $this->isTruthyEnvValue($this->readEnv('CLICKHOUSE_DERIVED_USE_INVENTORY_PROXY', '0'));
    }

    private function resolveDerivedInventoryProxyPriceCap(): float
    {
        $raw = trim($this->readEnv('CLICKHOUSE_DERIVED_INVENTORY_PRICE_CAP', '1000000'));
        if (!is_numeric($raw)) {
            return 1000000.0;
        }

        $value = (float)$raw;
        if ($value <= 0) {
            return 1000000.0;
        }

        return min($value, 1000000000.0);
    }

    private function resolveDerivedDeadStockMode(): string
    {
        $mode = strtolower(trim($this->readEnv('CLICKHOUSE_DERIVED_DEAD_STOCK_MODE', 'net_add')));
        if (!in_array($mode, ['net_add', 'subtract'], true)) {
            return 'net_add';
        }

        return $mode;
    }

    private function resolveDerivedDeadStockFactor(): float
    {
        $raw = trim($this->readEnv('CLICKHOUSE_DERIVED_DEAD_STOCK_FACTOR', '0.25'));
        if (!is_numeric($raw)) {
            return 0.25;
        }

        $value = (float)$raw;
        if ($value < 0) {
            return 0.0;
        }

        return min($value, 1.0);
    }

    private function isTruthyEnvValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function isConfigured(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }

        if ($this->useDerivedSource()) {
            return true;
        }

        return $this->table !== '';
    }

    private function readEnv(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
        if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
            return trim((string)$_ENV[$key]);
        }
        return $default;
    }
}
