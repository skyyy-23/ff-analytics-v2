<?php
require_once __DIR__ . '/HealthScoring.php';
class FranchiseHealthService
{
    private $branchRepository;
    private $scoring;

    public function __construct(BranchRepositoryInterface $branchRepository)
    {
        $this->branchRepository = $branchRepository;
        $this->scoring = new HealthScoring();
    }

    public function getBranches(): array
    {
        return $this->branchRepository->getBranches();
    }

    public function getBranchHealthListFormatted(): array
    {
        $result = [];
        foreach ($this->getBranches() as $branch) {
            $health = $this->getBranchHealthDetailFromData($branch);
            if ($health !== null) {
                $result[] = $health;
            }
        }

        usort($result, function (array $a, array $b): int {
            $scoreCompare = ((int)($b['overall_score'] ?? 0)) <=> ((int)($a['overall_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string)($a['branch_name'] ?? ''), (string)($b['branch_name'] ?? ''));
        });

        return $result;
    }

    public function getBranchHealthDetailFormatted($branchName): ?array
    {
        $b = $this->branchRepository->findBranch((string)$branchName);
        if (!$b) {
            return null;
        }

        return $this->getBranchHealthDetailFromData($b);
    }

    public function getBranchHealthDetailFromData(array $branch): ?array
    {
        if (!$this->hasRequiredBranchFields($branch)) {
            return null;
        }

        $result = $this->scoring->scoreBranch($branch);

        [$status, $statusText] = StatusCode::fromScore($result['overall_score']);

        $payload = [
            'branch_name'   => (string)$branch['branch'],
            'overall_score' => $result['overall_score'],
            'status'        => $status,
            'status_text'   => $statusText,
            'status_key'    => strtolower($status),

            'factors'       => $result['factors'] ?? [],

            'interpretation'=> InterpretationCode::fromScore($result['overall_score']),
        ];

        $reportingPeriod = $this->normalizeReportingDate($branch['reporting_period'] ?? null);
        if ($reportingPeriod !== null) {
            $payload['reporting_period'] = $reportingPeriod;
            $payload['reporting_range_start'] = date('Y-m-01', strtotime($reportingPeriod));
            $payload['reporting_range_end'] = date('Y-m-t', strtotime($reportingPeriod));
        }

        return $payload;
    }

    public function getMonthlyComparisonHistoryFormatted(string $branchName = ''): array
    {
        $requestedBranchName = trim($branchName);
        $reports = $this->branchRepository->getMonthlyReports(
            $requestedBranchName !== '' ? $requestedBranchName : null,
            $this->resolveHistoryMonthLimit()
        );
        if (!is_array($reports) || !$reports) {
            return [
                'months' => [],
                'uses_reporting_period' => false,
                'is_branch_scope' => $requestedBranchName !== '',
                'selected_branch' => $requestedBranchName,
                'generated_at' => gmdate('c'),
            ];
        }

        $defaultMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        $usesReportingPeriod = false;
        $matchedBranchName = '';
        $grouped = [];

        foreach ($reports as $report) {
            if (!is_array($report) || !$this->hasRequiredBranchFields($report)) {
                continue;
            }

            if ($requestedBranchName !== '') {
                $reportBranchName = trim((string)($report['branch'] ?? ''));
                if ($reportBranchName === '' || strcasecmp($reportBranchName, $requestedBranchName) !== 0) {
                    continue;
                }
                if ($matchedBranchName === '') {
                    $matchedBranchName = $reportBranchName;
                }
            }

            $monthKey = $this->normalizeMonthKey($report['reporting_period'] ?? null);
            if ($monthKey === null) {
                $monthKey = $defaultMonth;
            } else {
                $usesReportingPeriod = true;
            }

            $health = $this->getBranchHealthDetailFromData($report);
            if ($health === null) {
                continue;
            }

            if (!isset($grouped[$monthKey])) {
                $grouped[$monthKey] = [
                    'month_key' => $monthKey,
                    'month_label' => $this->formatMonthLabel($monthKey),
                    'branches' => [],
                ];
            }

            $branchEntry = [
                'branch_name' => $health['branch_name'],
                'overall_score' => $health['overall_score'],
                'status' => $health['status'],
                'status_key' => $health['status_key'],
                'status_text' => $health['status_text'],
            ];

            if ($requestedBranchName !== '') {
                $branchEntry['factors'] = array_map(function ($factor): array {
                    $safeFactor = is_array($factor) ? $factor : [];
                    return [
                        'name' => (string)($safeFactor['name'] ?? ''),
                        'raw_basis' => (string)($safeFactor['raw_basis'] ?? ''),
                        'score' => isset($safeFactor['score']) ? (int)$safeFactor['score'] : 0,
                        'weight' => isset($safeFactor['weight']) ? (int)$safeFactor['weight'] : 0,
                    ];
                }, is_array($health['factors']) ? $health['factors'] : []);
            }

            $grouped[$monthKey]['branches'][] = $branchEntry;
        }

        $currentMonthKey = $defaultMonth;
        $months = array_values(array_filter($grouped, function (array $month) use ($currentMonthKey): bool {
            return (string)($month['month_key'] ?? '') !== $currentMonthKey;
        }));
        foreach ($months as &$month) {
            usort($month['branches'], function (array $a, array $b): int {
                return $b['overall_score'] <=> $a['overall_score'];
            });

            $branchCount = count($month['branches']);
            $scoreTotal = 0;
            $riskCount = 0;
            foreach ($month['branches'] as $branch) {
                $scoreTotal += (int)$branch['overall_score'];
                if ($branch['status_key'] === 'warning' || $branch['status_key'] === 'critical') {
                    $riskCount++;
                }
            }

            $month['branch_count'] = $branchCount;
            $month['average_score'] = $branchCount > 0 ? (int)round($scoreTotal / $branchCount) : 0;
            $month['risk_count'] = $riskCount;
            $month['top_branch'] = $branchCount > 0 ? $month['branches'][0] : null;
            $month['bottom_branch'] = $branchCount > 0 ? $month['branches'][$branchCount - 1] : null;
        }
        unset($month);

        usort($months, function (array $a, array $b): int {
            return strcmp($b['month_key'], $a['month_key']);
        });

        return [
            'months' => $months,
            'uses_reporting_period' => $usesReportingPeriod,
            'is_branch_scope' => $requestedBranchName !== '',
            'selected_branch' => $matchedBranchName !== '' ? $matchedBranchName : $requestedBranchName,
            'generated_at' => gmdate('c'),
        ];
    }

    public function getYearlyComparisonHistoryFormatted(string $branchName = ''): array
    {
        $requestedBranchName = trim($branchName);
        $reports = $this->branchRepository->getMonthlyReports(
            $requestedBranchName !== '' ? $requestedBranchName : null,
            $this->resolveHistoryYearMonthLimit()
        );
        if (!is_array($reports) || !$reports) {
            return [
                'years' => [],
                'uses_reporting_period' => false,
                'is_branch_scope' => $requestedBranchName !== '',
                'selected_branch' => $requestedBranchName,
                'generated_at' => gmdate('c'),
            ];
        }

        $defaultYear = (new DateTimeImmutable('now'))->format('Y');
        $usesReportingPeriod = false;
        $matchedBranchName = '';
        $grouped = [];

        foreach ($reports as $report) {
            if (!is_array($report) || !$this->hasRequiredBranchFields($report)) {
                continue;
            }

            if ($requestedBranchName !== '') {
                $reportBranchName = trim((string)($report['branch'] ?? ''));
                if ($reportBranchName === '' || strcasecmp($reportBranchName, $requestedBranchName) !== 0) {
                    continue;
                }
                if ($matchedBranchName === '') {
                    $matchedBranchName = $reportBranchName;
                }
            }

            $monthKey = $this->normalizeMonthKey($report['reporting_period'] ?? null);
            if ($monthKey === null) {
                $yearKey = $defaultYear;
            } else {
                $usesReportingPeriod = true;
                $yearKey = $this->extractYearKey($monthKey) ?? $defaultYear;
            }

            $health = $this->getBranchHealthDetailFromData($report);
            if ($health === null) {
                continue;
            }

            if (!isset($grouped[$yearKey])) {
                $grouped[$yearKey] = [
                    'year_key' => $yearKey,
                    'year_label' => $this->formatYearLabel($yearKey),
                    'branches' => [],
                ];
            }

            $branchNameKey = trim((string)($health['branch_name'] ?? ''));
            if ($branchNameKey === '') {
                continue;
            }

            if (!isset($grouped[$yearKey]['branches'][$branchNameKey])) {
                $grouped[$yearKey]['branches'][$branchNameKey] = [
                    'branch_name' => $branchNameKey,
                    'score_total' => 0,
                    'sample_count' => 0,
                    'factors_accum' => [],
                ];
            }

            $grouped[$yearKey]['branches'][$branchNameKey]['score_total'] += (int)$health['overall_score'];
            $grouped[$yearKey]['branches'][$branchNameKey]['sample_count']++;

            if ($requestedBranchName !== '') {
                $factors = is_array($health['factors']) ? $health['factors'] : [];
                foreach ($factors as $factor) {
                    if (!is_array($factor)) {
                        continue;
                    }
                    $factorName = trim((string)($factor['name'] ?? ''));
                    if ($factorName === '') {
                        continue;
                    }

                    if (!isset($grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName])) {
                        $grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName] = [
                            'name' => $factorName,
                            'score_total' => 0,
                            'score_count' => 0,
                            'weight_total' => 0,
                            'weight_count' => 0,
                        ];
                    }

                    if (isset($factor['score'])) {
                        $grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName]['score_total'] += (int)$factor['score'];
                        $grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName]['score_count']++;
                    }
                    if (isset($factor['weight'])) {
                        $grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName]['weight_total'] += (int)$factor['weight'];
                        $grouped[$yearKey]['branches'][$branchNameKey]['factors_accum'][$factorName]['weight_count']++;
                    }
                }
            }
        }

        $years = [];
        foreach ($grouped as $yearKey => $yearData) {
            $branches = [];
            foreach ($yearData['branches'] as $branchAgg) {
                $sampleCount = isset($branchAgg['sample_count']) ? (int)$branchAgg['sample_count'] : 0;
                if ($sampleCount <= 0) {
                    continue;
                }

                $averageScore = (int)round(((int)$branchAgg['score_total']) / $sampleCount);
                [$status, $statusText] = StatusCode::fromScore($averageScore);
                $branchEntry = [
                    'branch_name' => (string)($branchAgg['branch_name'] ?? ''),
                    'overall_score' => $averageScore,
                    'status' => $status,
                    'status_key' => strtolower($status),
                    'status_text' => $statusText,
                    'sample_count' => $sampleCount,
                ];

                if ($requestedBranchName !== '') {
                    $branchEntry['factors'] = $this->buildYearlyFactorBreakdown(
                        is_array($branchAgg['factors_accum']) ? $branchAgg['factors_accum'] : [],
                        $sampleCount
                    );
                }

                $branches[] = $branchEntry;
            }

            usort($branches, function (array $a, array $b): int {
                $scoreCompare = ((int)($b['overall_score'] ?? 0)) <=> ((int)($a['overall_score'] ?? 0));
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }
                return strcmp((string)($a['branch_name'] ?? ''), (string)($b['branch_name'] ?? ''));
            });

            $branchCount = count($branches);
            $scoreTotal = 0;
            $riskCount = 0;
            foreach ($branches as $branch) {
                $scoreTotal += (int)($branch['overall_score'] ?? 0);
                $statusKey = strtolower((string)($branch['status_key'] ?? ''));
                if ($statusKey === 'warning' || $statusKey === 'critical') {
                    $riskCount++;
                }
            }

            $years[] = [
                'year_key' => (string)$yearKey,
                'year_label' => (string)($yearData['year_label'] ?? $yearKey),
                'branches' => $branches,
                'branch_count' => $branchCount,
                'average_score' => $branchCount > 0 ? (int)round($scoreTotal / $branchCount) : 0,
                'risk_count' => $riskCount,
                'top_branch' => $branchCount > 0 ? $branches[0] : null,
                'bottom_branch' => $branchCount > 0 ? $branches[$branchCount - 1] : null,
            ];
        }

        usort($years, function (array $a, array $b): int {
            return strcmp((string)($b['year_key'] ?? ''), (string)($a['year_key'] ?? ''));
        });

        return [
            'years' => $years,
            'uses_reporting_period' => $usesReportingPeriod,
            'is_branch_scope' => $requestedBranchName !== '',
            'selected_branch' => $matchedBranchName !== '' ? $matchedBranchName : $requestedBranchName,
            'generated_at' => gmdate('c'),
        ];
    }

    private function hasRequiredBranchFields(array $branch): bool
    {
        $required = [
            'branch',
            'current_sales',
            'previous_sales',
            'expenses',
            'cogs',
            'avg_inventory',
            'dead_stock',
            'expected_pos_days',
            'actual_pos_days',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $branch)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeMonthKey($reportingPeriod): ?string
    {
        if (!is_string($reportingPeriod)) {
            return null;
        }

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

    private function normalizeReportingDate($reportingPeriod): ?string
    {
        if ($reportingPeriod instanceof DateTimeInterface) {
            return $reportingPeriod->format('Y-m-d');
        }

        if (!is_string($reportingPeriod) && !is_int($reportingPeriod) && !is_float($reportingPeriod)) {
            return null;
        }

        $clean = trim((string)$reportingPeriod);
        if ($clean === '') {
            return null;
        }

        $timestamp = strtotime($clean);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function extractYearKey(string $monthKey): ?string
    {
        $clean = trim($monthKey);
        if (!preg_match('/^\d{4}-\d{2}$/', $clean)) {
            return null;
        }

        return substr($clean, 0, 4);
    }

    private function formatMonthLabel(string $monthKey): string
    {
        $timestamp = strtotime($monthKey . '-01');
        if ($timestamp === false) {
            return $monthKey;
        }
        return date('F Y', $timestamp);
    }

    private function formatYearLabel(string $yearKey): string
    {
        $clean = trim($yearKey);
        if (!preg_match('/^\d{4}$/', $clean)) {
            return $yearKey;
        }

        return $clean;
    }

    private function buildYearlyFactorBreakdown(array $factorAccumulator, int $sampleCount): array
    {
        $result = [];
        foreach ($factorAccumulator as $factor) {
            if (!is_array($factor)) {
                continue;
            }

            $name = trim((string)($factor['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $scoreCount = isset($factor['score_count']) ? (int)$factor['score_count'] : 0;
            $weightCount = isset($factor['weight_count']) ? (int)$factor['weight_count'] : 0;

            $result[] = [
                'name' => $name,
                'raw_basis' => sprintf(
                    'Yearly average across %d month%s',
                    $sampleCount,
                    $sampleCount === 1 ? '' : 's'
                ),
                'score' => $scoreCount > 0 ? (int)round(((int)($factor['score_total'] ?? 0)) / $scoreCount) : 0,
                'weight' => $weightCount > 0 ? (int)round(((int)($factor['weight_total'] ?? 0)) / $weightCount) : 0,
            ];
        }

        return $result;
    }

    private function resolveHistoryMonthLimit(): int
    {
        $keys = ['HISTORY_MONTH_LIMIT'];
        foreach ($keys as $key) {
            $raw = getenv($key);
            if ($raw === false || trim((string)$raw) === '') {
                continue;
            }

            $value = (int)$raw;
            if ($value > 0) {
                return min($value, 60);
            }
        }

        return 12;
    }

    private function resolveHistoryYearMonthLimit(): int
    {
        $keys = ['HISTORY_YEAR_MONTH_LIMIT'];
        foreach ($keys as $key) {
            $raw = getenv($key);
            if ($raw === false || trim((string)$raw) === '') {
                continue;
            }

            $value = (int)$raw;
            if ($value > 0) {
                return min($value, 60);
            }
        }

        return 60;
    }
}
