<?php
require_once __DIR__ . '/BranchRepositoryInterface.php';
require_once __DIR__ . '/../../services/Env.php';

class SupabaseBranchRepository implements BranchRepositoryInterface
{
    private $baseUrl;
    private $apiKey;
    private $table;
    private $monthCreatedColumn;
    private $branches;
    private $branchesByName;

    public function __construct()
    {
        Env::load(__DIR__ . '/../..');

        $this->baseUrl = rtrim($this->readEnv('SUPABASE_URL', ''), '/');
        $this->apiKey = $this->readEnv('SUPABASE_KEY', '');
        $this->table = $this->readEnv('SUPABASE_TABLE', 'v_branch_metrics');
        $this->monthCreatedColumn = $this->normalizeColumnName(
            $this->readEnv('SUPABASE_MONTH_COLUMN', 'month_created'),
            'month_created'
        );
        $this->branches = null;
        $this->branchesByName = null;
    }

    public function getBranches(): array
    {
        $this->loadBranches();
        return $this->branches;
    }

    public function findBranch(string $branchName): ?array
    {
        $this->loadBranches();
        $name = trim($branchName);
        if ($name === '') {
            return null;
        }

        return $this->branchesByName[$name] ?? null;
    }

    public function getMonthlyReports(?string $branchName = null, ?int $monthLimit = null): array
    {
        $rows = $this->fetchMonthlyRows();
        if (!$rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeRow($row);
            if ($normalized === null) {
                continue;
            }

            $reportingPeriod = $this->normalizeReportingPeriod(
                $row[$this->monthCreatedColumn] ?? ($row['reporting_period'] ?? ($row['month'] ?? null))
            );
            if ($reportingPeriod !== null) {
                $normalized['reporting_period'] = $reportingPeriod;
            }

            $result[] = $normalized;
        }

        $normalizedBranch = trim((string)$branchName);
        if ($normalizedBranch !== '') {
            $result = array_values(array_filter($result, function (array $row) use ($normalizedBranch): bool {
                return strcasecmp((string)($row['branch'] ?? ''), $normalizedBranch) === 0;
            }));
        }

        usort($result, function (array $a, array $b): int {
            $monthCompare = strcmp((string)($b['reporting_period'] ?? ''), (string)($a['reporting_period'] ?? ''));
            if ($monthCompare !== 0) {
                return $monthCompare;
            }
            return strcmp((string)($a['branch'] ?? ''), (string)($b['branch'] ?? ''));
        });

        $limit = $this->normalizeMonthLimit($monthLimit);
        if ($limit === null) {
            return $result;
        }

        $allowedMonths = [];
        $limited = [];
        foreach ($result as $row) {
            $monthKey = $this->toMonthKey((string)($row['reporting_period'] ?? ''));
            if ($monthKey === null) {
                continue;
            }

            if (!isset($allowedMonths[$monthKey])) {
                if (count($allowedMonths) >= $limit) {
                    continue;
                }
                $allowedMonths[$monthKey] = true;
            }

            $limited[] = $row;
        }

        return $limited;
    }

    private function loadBranches(): void
    {
        if ($this->branches !== null) {
            return;
        }

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->table === '') {
            $this->branches = [];
            $this->branchesByName = [];
            return;
        }

        $rows = $this->fetchRowsWithFallback([
            'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days',
        ], true);
        $this->branches = [];
        $this->branchesByName = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeRow($row);
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

    private function fetchMonthlyRows(): array
    {
        return $this->fetchRowsWithFallback([
            sprintf(
                'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days,%s',
                $this->monthCreatedColumn
            ),
            sprintf(
                'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days,%s,reporting_period',
                $this->monthCreatedColumn
            ),
            'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days,reporting_period',
            'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days,month',
            'branch,current_sales,previous_sales,expenses,cogs,avg_inventory,dead_stock,expected_pos_days,actual_pos_days',
        ], false);
    }

    private function fetchRowsWithFallback(array $selectCandidates, bool $currentMonthOnly): array
    {
        foreach ($selectCandidates as $select) {
            $rows = $this->fetchRows($select, $currentMonthOnly);
            if ($rows !== null) {
                return $rows;
            }
        }

        return [];
    }

    private function fetchRows(string $select, bool $currentMonthOnly): ?array
    {
        $path = '/rest/v1/' . rawurlencode($this->table);
        $params = [
            'select' => $select,
        ];

        if ($currentMonthOnly) {
            $currentMonthStart = new DateTimeImmutable('first day of this month 00:00:00');
            $nextMonthStart = $currentMonthStart->modify('+1 month');
            $params['and'] = sprintf(
                '(%s.gte.%s,%s.lt.%s)',
                $this->monthCreatedColumn,
                $currentMonthStart->format('Y-m-d'),
                $this->monthCreatedColumn,
                $nextMonthStart->format('Y-m-d')
            );
        }

        $url = $this->baseUrl . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->apiKey,
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeRow(array $row): ?array
    {
        $branch = isset($row['branch']) ? trim((string)$row['branch']) : '';
        if ($branch === '') {
            return null;
        }

        return [
            'branch' => $branch,
            'current_sales' => (float)($row['current_sales'] ?? 0),
            'previous_sales' => (float)($row['previous_sales'] ?? 0),
            'expenses' => (float)($row['expenses'] ?? 0),
            'cogs' => (float)($row['cogs'] ?? 0),
            'avg_inventory' => (float)($row['avg_inventory'] ?? 0),
            'dead_stock' => (float)($row['dead_stock'] ?? 0),
            'expected_pos_days' => (int)($row['expected_pos_days'] ?? 0),
            'actual_pos_days' => (int)($row['actual_pos_days'] ?? 0),
        ];
    }

    private function normalizeMonthLimit(?int $monthLimit): ?int
    {
        if ($monthLimit === null) {
            return null;
        }

        $limit = (int)$monthLimit;
        if ($limit <= 0) {
            return null;
        }

        return min($limit, 60);
    }

    private function normalizeReportingPeriod($value): ?string
    {
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

    private function toMonthKey(string $reportingPeriod): ?string
    {
        $clean = trim($reportingPeriod);
        if ($clean === '') {
            return null;
        }

        $timestamp = strtotime($clean);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m', $timestamp);
    }

    private function readEnv(string $key, string $default = ''): string
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

    private function normalizeColumnName(string $column, string $default): string
    {
        $candidate = trim($column);
        if ($candidate === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate)) {
            return $default;
        }

        return $candidate;
    }
}
