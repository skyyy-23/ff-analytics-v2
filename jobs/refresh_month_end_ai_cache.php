<?php
require_once __DIR__ . '/../app/bootstrap.php';

/**
 * Prewarms AI caches for the active reporting period.
 *
 * Usage:
 *   php jobs/refresh_month_end_ai_cache.php
 *   php jobs/refresh_month_end_ai_cache.php --force
 *   php jobs/refresh_month_end_ai_cache.php --force --limit=10
 */

function cli_option_present(array $argv, string $name): bool
{
    $needle = '--' . $name;
    foreach ($argv as $arg) {
        if ($arg === $needle) {
            return true;
        }
    }
    return false;
}

function cli_option_value(array $argv, string $name): ?string
{
    $needle = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $needle) === 0) {
            return substr($arg, strlen($needle));
        }
    }
    return null;
}

function is_last_day_of_month(): bool
{
    $today = new DateTimeImmutable('now');
    return $today->format('d') === $today->format('t');
}

$force = cli_option_present($argv, 'force');
$limitRaw = cli_option_value($argv, 'limit');
$branchLimit = 0;
if ($limitRaw !== null && trim($limitRaw) !== '') {
    $parsed = (int)$limitRaw;
    if ($parsed > 0) {
        $branchLimit = $parsed;
    }
}

if (!$force && !is_last_day_of_month()) {
    echo '[skip] Today is not the last day of month. Use --force to run anyway.' . PHP_EOL;
    exit(0);
}

if ($force) {
    putenv('AI_MONTH_END_CACHE_REFRESH_FORCE=1');
    $_ENV['AI_MONTH_END_CACHE_REFRESH_FORCE'] = '1';
    putenv('AI_YEAR_END_CACHE_REFRESH_FORCE=1');
    $_ENV['AI_YEAR_END_CACHE_REFRESH_FORCE'] = '1';
}

$insights = AppFactory::makeInsightsService();
$health = AppFactory::makeHealthService();

$summary = [
    'overview' => 'not_run',
    'monthly_narrative' => 'not_run',
    'yearly_narrative' => 'not_run',
    'branch_interpretation_ok' => 0,
    'branch_interpretation_fail' => 0,
];

try {
    $overview = $insights->getOverviewInsights();
    $summary['overview'] = (string)($overview['ai_status'] ?? ($overview['source'] ?? 'ok'));
    echo '[ok] overview cache refreshed (' . $summary['overview'] . ')' . PHP_EOL;
} catch (Throwable $e) {
    $summary['overview'] = 'error: ' . $e->getMessage();
    echo '[error] overview refresh failed: ' . $e->getMessage() . PHP_EOL;
}

try {
    $history = $health->getMonthlyComparisonHistoryFormatted();
    $months = (isset($history['months']) && is_array($history['months'])) ? $history['months'] : [];
    $latestMonthKey = '';
    if (!empty($months)) {
        $latestMonthKey = trim((string)($months[0]['month_key'] ?? ''));
    }

    if ($latestMonthKey !== '') {
        $monthly = $insights->getMonthlyNarrative($latestMonthKey, '');
        $summary['monthly_narrative'] = (string)($monthly['ai_status'] ?? ($monthly['source'] ?? 'ok'));
        echo '[ok] monthly narrative cache refreshed for ' . $latestMonthKey . ' (' . $summary['monthly_narrative'] . ')' . PHP_EOL;
    } else {
        $summary['monthly_narrative'] = 'no_month_data';
        echo '[skip] monthly narrative refresh skipped (no month data).' . PHP_EOL;
    }
} catch (Throwable $e) {
    $summary['monthly_narrative'] = 'error: ' . $e->getMessage();
    echo '[error] monthly narrative refresh failed: ' . $e->getMessage() . PHP_EOL;
}

try {
    $history = $health->getYearlyComparisonHistoryFormatted();
    $years = (isset($history['years']) && is_array($history['years'])) ? $history['years'] : [];
    $latestYearKey = '';
    if (!empty($years)) {
        $latestYearKey = trim((string)($years[0]['year_key'] ?? ''));
    }

    if ($latestYearKey !== '') {
        $yearly = $insights->getYearlyNarrative($latestYearKey, '');
        $summary['yearly_narrative'] = (string)($yearly['ai_status'] ?? ($yearly['source'] ?? 'ok'));
        echo '[ok] yearly narrative cache refreshed for ' . $latestYearKey . ' (' . $summary['yearly_narrative'] . ')' . PHP_EOL;
    } else {
        $summary['yearly_narrative'] = 'no_year_data';
        echo '[skip] yearly narrative refresh skipped (no year data).' . PHP_EOL;
    }
} catch (Throwable $e) {
    $summary['yearly_narrative'] = 'error: ' . $e->getMessage();
    echo '[error] yearly narrative refresh failed: ' . $e->getMessage() . PHP_EOL;
}

$branches = $health->getBranchHealthListFormatted();
if ($branchLimit > 0) {
    $branches = array_slice($branches, 0, $branchLimit);
}

foreach ($branches as $branch) {
    $branchName = trim((string)($branch['branch_name'] ?? ''));
    if ($branchName === '') {
        continue;
    }

    try {
        $response = $insights->getBranchInterpretation($branchName);
        $status = (string)($response['ai_status'] ?? ($response['source'] ?? 'ok'));
        echo '[ok] branch cached: ' . $branchName . ' (' . $status . ')' . PHP_EOL;
        $summary['branch_interpretation_ok']++;
    } catch (Throwable $e) {
        echo '[error] branch cache failed: ' . $branchName . ' - ' . $e->getMessage() . PHP_EOL;
        $summary['branch_interpretation_fail']++;
    }
}

echo PHP_EOL . '=== AI Cache Refresh Summary ===' . PHP_EOL;
echo 'overview: ' . $summary['overview'] . PHP_EOL;
echo 'monthly_narrative: ' . $summary['monthly_narrative'] . PHP_EOL;
echo 'yearly_narrative: ' . $summary['yearly_narrative'] . PHP_EOL;
echo 'branch_interpretation_ok: ' . $summary['branch_interpretation_ok'] . PHP_EOL;
echo 'branch_interpretation_fail: ' . $summary['branch_interpretation_fail'] . PHP_EOL;
