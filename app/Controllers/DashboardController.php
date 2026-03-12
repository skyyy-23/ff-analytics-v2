<?php
class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('dashboard.index');
    }

    public function detail(Request $request): void
    {
        $branchName = trim((string)$request->query('branch', ''));
        $pageTitle = $branchName !== '' ? $branchName . ' | FH' : 'Branch Detail | FH';

        $this->view('dashboard.detail', [
            'branchName' => $branchName,
            'pageTitle' => $pageTitle,
        ]);
    }

    public function history(Request $request): void
    {
        $branchName = trim((string)$request->query('branch', ''));
        $period = strtolower(trim((string)$request->query('period', 'monthly')));
        if ($period !== 'yearly') {
            $period = 'monthly';
        }
        $isBranchScope = $branchName !== '';
        $periodLabel = $period === 'yearly' ? 'Yearly' : 'Monthly';
        $periodComparisonLabel = $period === 'yearly' ? 'Yearly Comparison History' : 'Monthly Comparison History';

        $pageTitle = $isBranchScope
            ? $branchName . ' ' . $periodLabel . ' History | FH'
            : $periodComparisonLabel . ' | FH';
        $heading = $isBranchScope
            ? $periodLabel . ' History | ' . $branchName
            : $periodComparisonLabel;
        $subtitle = $isBranchScope
            ? (
                $period === 'yearly'
                    ? 'Year-by-year comparison for the selected branch.'
                    : 'Month-by-month comparison for the selected branch.'
            )
            : (
                $period === 'yearly'
                    ? 'Compare all branches by reporting year.'
                    : 'Compare all branches by reporting month.'
            );
        $backHref = $isBranchScope
            ? 'detail.php?branch=' . rawurlencode($branchName)
            : 'index.php';
        $backLabel = $isBranchScope
            ? 'Back to Branch Detail'
            : 'Back to Overview';

        $this->view('dashboard.history', [
            'pageTitle' => $pageTitle,
            'historyHeading' => $heading,
            'historySubtitle' => $subtitle,
            'historyBranchName' => $branchName,
            'historyPeriod' => $period,
            'historyBackHref' => $backHref,
            'historyBackLabel' => $backLabel,
        ]);
    }
}
