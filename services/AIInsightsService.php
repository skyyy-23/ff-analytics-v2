<?php
require_once __DIR__ . '/../app/Models/BranchRepositoryInterface.php';
require_once __DIR__ . '/FranchiseHealthService.php';
require_once __DIR__ . '/GroqClient.php';
require_once __DIR__ . '/Env.php';

class AIInsightsService
{
    private const SALES_GROWTH_TARGET = 0.05;       // 5%
    private const NET_MARGIN_TARGET = 0.15;         // 15%
    private const EXPENSE_RATIO_LIMIT = 0.65;       // 65%
    private const DEAD_STOCK_RATIO_LIMIT = 0.20;    // 20%
    private const POS_COMPLIANCE_TARGET = 0.95;     // 95%
    private const EXPENSE_RATIO_ANOMALY_LIMIT = 10.0; // 1000%
    private const DEAD_STOCK_RATIO_ANOMALY_LIMIT = 1.0; // 100%

    private $branchRepository;
    private $healthService;
    private $groqClient;
    private $knownBranchNamesCache = null;
    private $cacheBaseUrl;
    private $cacheApiKey;
    private $cacheTable;
    private $branchCacheTable;
    private $monthlyNarrativeCacheTable;
    private $monthlyBranchNarrativeCacheTable;
    private $yearlyNarrativeCacheTable;
    private $lastAiStatus = 'unknown';
    private $lastAiError = null;

    public function __construct(
        BranchRepositoryInterface $branchRepository,
        FranchiseHealthService $healthService,
        ?GroqClient $groqClient = null
    )
    {
        Env::load(__DIR__ . '/..');

        $this->branchRepository = $branchRepository;
        $this->healthService = $healthService;
        $this->groqClient = $groqClient;
        $this->cacheBaseUrl = rtrim($this->readEnv('SUPABASE_URL', ''), '/');
        $this->cacheApiKey = $this->readEnv('SUPABASE_KEY', '');
        $this->cacheTable = $this->readEnv('AI_CACHE_TABLE', 'ai_insights_cache');
        $this->branchCacheTable = $this->readEnv('AI_BRANCH_CACHE_TABLE', 'ai_branch_interpretation_cache');
        $this->monthlyNarrativeCacheTable = $this->readEnv('AI_MONTHLY_CACHE_TABLE', 'ai_monthly_narrative_cache');
        $this->monthlyBranchNarrativeCacheTable = $this->readEnv(
            'AI_MONTHLY_BRANCH_CACHE_TABLE',
            'ai_monthly_branch_narrative_cache'
        );
        $this->yearlyNarrativeCacheTable = $this->readEnv(
            'AI_YEARLY_CACHE_TABLE',
            $this->monthlyNarrativeCacheTable
        );

        // AI remains optional; deterministic fallback is used when key/API is unavailable.
        if ($this->groqClient === null) {
            try {
                $this->groqClient = new GroqClient();
            } catch (Exception $e) {
                $this->groqClient = null;
            }
        }
    }

    /**
     * Deterministic, formula-grounded overview insights.
     *
     * @return array {summary: string, recommendations: array, source: string}
     */
    public function getOverviewInsights(): array
    {
        $this->resetAiDiagnostics();
        $branches = $this->branchRepository->getBranches();
        if (!$branches) {
            return [
                'summary' => 'No branch data available.',
                'recommendations' => [],
                'source' => 'fallback',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $analysis = [];
        foreach ($branches as $branch) {
            $analysis[] = $this->analyzeBranch($branch);
        }

        $portfolio = $this->buildPortfolioAnalysis($analysis);
        $fallbackSummary = $this->buildOverviewSummary($portfolio);
        $fallbackRecommendations = $this->buildOverviewRecommendations($portfolio);
        $monthKey = $this->resolveOverviewMonthKey();
        $dataHash = $this->buildOverviewCacheHash($analysis, $portfolio);
        $cacheRow = $this->readOverviewCacheRow($monthKey);
        $cached = $this->extractCachePayload($cacheRow);
        if ($this->shouldReuseMonthlyCache($cacheRow, $dataHash, $monthKey) && $cached !== null) {
            return [
                'summary' => (string)($cached['summary'] ?? $fallbackSummary),
                'recommendations' => (
                    isset($cached['recommendations']) && is_array($cached['recommendations']) && !empty($cached['recommendations'])
                        ? $cached['recommendations']
                        : $fallbackRecommendations
                ),
                'source' => 'ai',
                'ai_status' => 'cached',
                'ai_error' => null,
            ];
        }

        $ai = $this->buildOverviewInsightsWithAi($portfolio, $analysis);
        $hasAiContent = is_array($ai) && (!empty($ai['summary']) || !empty($ai['recommendations']));
        if ($hasAiContent) {
            $this->writeOverviewCache($monthKey, $dataHash, [
                'summary' => (string)($ai['summary'] ?? $fallbackSummary),
                'recommendations' => (
                    isset($ai['recommendations']) && is_array($ai['recommendations']) && !empty($ai['recommendations'])
                        ? $ai['recommendations']
                        : $fallbackRecommendations
                ),
            ]);
        }

        return [
            'summary' => $ai['summary'] ?? $fallbackSummary,
            'recommendations' => (!empty($ai['recommendations']) ? $ai['recommendations'] : $fallbackRecommendations),
            'source' => $hasAiContent ? 'ai' : 'fallback',
            'ai_status' => $hasAiContent ? 'generated' : $this->resolveFallbackAiStatus(),
            'ai_error' => $hasAiContent ? null : $this->lastAiError,
        ];
    }

    /**
     * Deterministic, formula-grounded branch interpretation.
     *
     * @param string $branchName Branch name from URL.
     * @return array {interpretation: array, source: string, error?: string}
     */
    public function getBranchInterpretation(string $branchName): array
    {
        $this->resetAiDiagnostics();
        $branch = $this->branchRepository->findBranch($branchName);
        if (!$branch) {
            return [
                'interpretation' => [],
                'source' => 'fallback',
                'error' => 'Branch not found.',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $analysis = $this->analyzeBranch($branch);
        $fallbackInterpretation = $this->buildBranchInterpretation($analysis);
        $monthKey = $this->resolveBranchMonthKey($branchName);
        $dataHash = $this->buildBranchInterpretationCacheHash($analysis);
        $cacheRow = $this->readBranchInterpretationCacheRow($monthKey, $branchName);
        if (!is_array($cacheRow)) {
            // Reuse latest branch cache when exact month row is missing.
            $cacheRow = $this->readLatestBranchInterpretationCacheRow($branchName);
        }
        $cached = $this->extractCachePayload($cacheRow);
        $cachedInterpretation = (
            is_array($cached) &&
            isset($cached['interpretation']) &&
            is_array($cached['interpretation']) &&
            !empty($cached['interpretation'])
        ) ? $cached['interpretation'] : [];
        $cachedMonthKey = is_array($cacheRow) ? trim((string)($cacheRow['month_key'] ?? '')) : '';
        $isExactMonthCache = ($cachedMonthKey !== '' && $cachedMonthKey === $monthKey);
        if (
            !empty($cachedInterpretation) &&
            (
                !$isExactMonthCache ||
                $this->shouldReuseMonthlyCache($cacheRow, $dataHash, $monthKey)
            )
        ) {
            return [
                'interpretation' => $cachedInterpretation,
                'source' => 'ai',
                'ai_status' => 'cached',
                'ai_error' => null,
            ];
        }

        $aiInterpretation = $this->buildBranchInterpretationWithAi($analysis);
        $hasAiInterpretation = is_array($aiInterpretation) && !empty($aiInterpretation);
        if ($hasAiInterpretation) {
            $this->writeBranchInterpretationCache($monthKey, $branchName, $dataHash, [
                'interpretation' => $aiInterpretation,
            ]);
        }

        return [
            'interpretation' => ($hasAiInterpretation ? $aiInterpretation : $fallbackInterpretation),
            'source' => ($hasAiInterpretation ? 'ai' : 'fallback'),
            'ai_status' => ($hasAiInterpretation ? 'generated' : $this->resolveFallbackAiStatus()),
            'ai_error' => ($hasAiInterpretation ? null : $this->lastAiError),
        ];
    }

    /**
     * Monthly AI narrative for comparison history.
     *
     * @param string $monthKey Month key in YYYY-MM format.
     * @param string $branchName Optional branch filter for branch-specific monthly narrative.
     * @return array {month_key: string, month_label: string, narrative: array, source: string, branch_name?: string, error?: string}
     */
    public function getMonthlyNarrative(string $monthKey, string $branchName = ''): array
    {
        $this->resetAiDiagnostics();
        $monthKey = trim($monthKey);
        $branchName = trim($branchName);
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            return [
                'month_key' => $monthKey,
                'month_label' => $monthKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'Invalid month format. Use YYYY-MM.',
                'ai_status' => 'invalid_input',
                'ai_error' => null,
            ];
        }

        $history = $this->healthService->getMonthlyComparisonHistoryFormatted($branchName);
        $months = (isset($history['months']) && is_array($history['months'])) ? $history['months'] : [];
        if (!$months) {
            return [
                'month_key' => $monthKey,
                'month_label' => $monthKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'No monthly comparison data available.',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $selectedMonth = null;
        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }
            if ((string)($month['month_key'] ?? '') === $monthKey) {
                $selectedMonth = $month;
                break;
            }
        }

        if (!$selectedMonth) {
            return [
                'month_key' => $monthKey,
                'month_label' => $monthKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'Month not found in comparison history.',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $resolvedBranchName = (string)($history['selected_branch'] ?? $branchName);
        $branchContext = $this->buildSelectedBranchMonthlyContext(
            $resolvedBranchName,
            (string)($selectedMonth['month_key'] ?? $monthKey)
        );

        $portfolioContext = ($resolvedBranchName === '')
            ? $this->buildPortfolioMonthlyContext((string)($selectedMonth['month_key'] ?? $monthKey))
            : null;

        $fallbackNarrative = $this->buildMonthlyNarrative($selectedMonth, $branchContext, $portfolioContext);
        $dataHash = $this->buildMonthlyNarrativeCacheHash($selectedMonth, $branchContext, $resolvedBranchName, $portfolioContext);
        $cacheBranchName = $resolvedBranchName !== '' ? $resolvedBranchName : null;
        $cacheRow = $this->readMonthlyNarrativeCacheRow($monthKey, $cacheBranchName);
        $cachedPayload = $this->extractCachePayload($cacheRow);
        $cachedNarrative = (
            is_array($cachedPayload) &&
            isset($cachedPayload['narrative']) &&
            is_array($cachedPayload['narrative']) &&
            !empty($cachedPayload['narrative'])
        ) ? $cachedPayload['narrative'] : [];
        if ($this->shouldReuseMonthlyCache($cacheRow, $dataHash, $monthKey, true) && !empty($cachedNarrative)) {
            return [
                'month_key' => (string)($selectedMonth['month_key'] ?? $monthKey),
                'month_label' => (string)($selectedMonth['month_label'] ?? $monthKey),
                'narrative' => $cachedNarrative,
                'source' => 'ai',
                'branch_name' => $resolvedBranchName,
                'ai_status' => 'cached',
                'ai_error' => null,
            ];
        }

        $aiNarrative = $this->buildMonthlyNarrativeWithAi($selectedMonth, $branchContext, $portfolioContext);
        $hasAiNarrative = is_array($aiNarrative) && !empty($aiNarrative);
        if ($hasAiNarrative) {
            $this->writeMonthlyNarrativeCache($monthKey, $cacheBranchName, $dataHash, [
                'narrative' => $aiNarrative,
            ]);
        }

        return [
            'month_key' => (string)($selectedMonth['month_key'] ?? $monthKey),
            'month_label' => (string)($selectedMonth['month_label'] ?? $monthKey),
            'narrative' => ($hasAiNarrative ? $aiNarrative : $fallbackNarrative),
            'source' => ($hasAiNarrative ? 'ai' : 'deterministic'),
            'branch_name' => $resolvedBranchName,
            'ai_status' => ($hasAiNarrative ? 'generated' : $this->resolveFallbackAiStatus()),
            'ai_error' => ($hasAiNarrative ? null : $this->lastAiError),
        ];
    }

    /**
     * Yearly AI narrative for comparison history.
     *
     * @param string $yearKey Year key in YYYY format.
     * @param string $branchName Optional branch filter for branch-specific yearly narrative.
     * @return array {year_key: string, year_label: string, narrative: array, source: string, branch_name?: string, error?: string}
     */
    public function getYearlyNarrative(string $yearKey, string $branchName = ''): array
    {
        $this->resetAiDiagnostics();
        $yearKey = trim($yearKey);
        $branchName = trim($branchName);
        if (!preg_match('/^\d{4}$/', $yearKey)) {
            return [
                'year_key' => $yearKey,
                'year_label' => $yearKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'Invalid year format. Use YYYY.',
                'ai_status' => 'invalid_input',
                'ai_error' => null,
            ];
        }

        $history = $this->healthService->getYearlyComparisonHistoryFormatted($branchName);
        $years = (isset($history['years']) && is_array($history['years'])) ? $history['years'] : [];
        if (!$years) {
            return [
                'year_key' => $yearKey,
                'year_label' => $yearKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'No yearly comparison data available.',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $selectedYear = null;
        $selectedIndex = -1;
        foreach ($years as $index => $year) {
            if (!is_array($year)) {
                continue;
            }
            if ((string)($year['year_key'] ?? '') === $yearKey) {
                $selectedYear = $year;
                $selectedIndex = (int)$index;
                break;
            }
        }

        if (!$selectedYear) {
            return [
                'year_key' => $yearKey,
                'year_label' => $yearKey,
                'narrative' => [],
                'source' => 'fallback',
                'branch_name' => $branchName,
                'error' => 'Year not found in comparison history.',
                'ai_status' => 'no_data',
                'ai_error' => null,
            ];
        }

        $previousYear = null;
        if ($selectedIndex >= 0) {
            for ($i = $selectedIndex + 1; $i < count($years); $i++) {
                if (is_array($years[$i])) {
                    $previousYear = $years[$i];
                    break;
                }
            }
        }

        $resolvedBranchName = (string)($history['selected_branch'] ?? $branchName);
        $isBranchScope = $resolvedBranchName !== '';

        $fallbackNarrative = $this->buildYearlyNarrative($selectedYear, $previousYear, $isBranchScope);
        $dataHash = $this->buildYearlyNarrativeCacheHash($selectedYear, $previousYear, $resolvedBranchName);
        $cacheBranchName = $resolvedBranchName !== '' ? $resolvedBranchName : null;
        $cacheRow = $this->readYearlyNarrativeCacheRow($yearKey, $cacheBranchName);
        $cachedPayload = $this->extractCachePayload($cacheRow);
        $cachedNarrative = (
            is_array($cachedPayload) &&
            isset($cachedPayload['narrative']) &&
            is_array($cachedPayload['narrative']) &&
            !empty($cachedPayload['narrative'])
        ) ? $cachedPayload['narrative'] : [];
        if ($this->shouldReuseYearlyCache($cacheRow, $dataHash, $yearKey, true) && !empty($cachedNarrative)) {
            return [
                'year_key' => (string)($selectedYear['year_key'] ?? $yearKey),
                'year_label' => (string)($selectedYear['year_label'] ?? $yearKey),
                'previous_year_key' => is_array($previousYear) ? (string)($previousYear['year_key'] ?? '') : '',
                'narrative' => $cachedNarrative,
                'source' => 'ai',
                'branch_name' => $resolvedBranchName,
                'ai_status' => 'cached',
                'ai_error' => null,
            ];
        }

        $aiNarrative = $this->buildYearlyNarrativeWithAi($selectedYear, $previousYear, $isBranchScope);
        $hasAiNarrative = is_array($aiNarrative) && !empty($aiNarrative);
        if ($hasAiNarrative) {
            $this->writeYearlyNarrativeCache($yearKey, $cacheBranchName, $dataHash, [
                'narrative' => $aiNarrative,
            ]);
        }

        return [
            'year_key' => (string)($selectedYear['year_key'] ?? $yearKey),
            'year_label' => (string)($selectedYear['year_label'] ?? $yearKey),
            'previous_year_key' => is_array($previousYear) ? (string)($previousYear['year_key'] ?? '') : '',
            'narrative' => ($hasAiNarrative ? $aiNarrative : $fallbackNarrative),
            'source' => ($hasAiNarrative ? 'ai' : 'deterministic'),
            'branch_name' => $resolvedBranchName,
            'ai_status' => ($hasAiNarrative ? 'generated' : $this->resolveFallbackAiStatus()),
            'ai_error' => ($hasAiNarrative ? null : $this->lastAiError),
        ];
    }

    private function buildOverviewInsightsWithAi(array $portfolio, array $analysis): ?array
    {
        if (!$this->groqClient instanceof GroqClient) {
            return null;
        }

        $issues = $portfolio['issue_buckets'];
        $overviewBranches = $this->buildOverviewAiBranchRows($analysis);
        $overviewInput = [
            'formula_reference' => $this->buildFormulaReference(),
            'forward_risk_window_days' => [30, 60],
            'branch_count' => $portfolio['branch_count'],
            'status_counts' => $portfolio['status_counts'],
            'average_score' => $portfolio['average_score'],
            'branches_sample_size' => count($overviewBranches),
            'overall_branches_growth' => $this->formatPercent($portfolio['portfolio_growth']),
            'overall_branches_net_margin' => $this->formatPercent($portfolio['portfolio_net_margin']),
            'overall_branches_expense_ratio' => $this->formatPercent($portfolio['portfolio_expense_ratio']),
            'overall_branches_dead_stock_ratio' => $this->formatPercent($portfolio['portfolio_dead_stock_ratio']),
            'overall_branches_pos_compliance' => $this->formatPercent($portfolio['portfolio_pos_compliance']),
            'decision_overview' => $portfolio['decision_overview'] ?? [],
            'issue_counts' => [
                'weak_sales_growth' => count($issues['weak_sales_growth']),
                'low_net_margin' => count($issues['low_net_margin']),
                'high_expense_ratio' => count($issues['high_expense_ratio']),
                'high_dead_stock' => count($issues['high_dead_stock']),
                'low_pos_compliance' => count($issues['low_pos_compliance']),
            ],
            'top_branch' => $portfolio['top_branch'] ? [
                'branch_name' => $portfolio['top_branch']['branch_name'],
                'overall_score' => $portfolio['top_branch']['overall_score'],
                'status' => $portfolio['top_branch']['status'],
            ] : null,
            'bottom_branch' => $portfolio['bottom_branch'] ? [
                'branch_name' => $portfolio['bottom_branch']['branch_name'],
                'overall_score' => $portfolio['bottom_branch']['overall_score'],
                'status' => $portfolio['bottom_branch']['status'],
            ] : null,
            'branches' => $overviewBranches,
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a franchise operations analyst. Reply with strict JSON only and no markdown. Use only facts from the provided data and formula_reference. Do not invent numbers, thresholds, branch names, or benchmarks.',
            ],
            [
                'role' => 'user',
                'content' =>
                    'Generate an executive decision narrative from provided computed metrics and decision signals.' .
                    ' Return JSON with this exact schema: ' .
                    '{"summary":"string","recommendations":[{"priority":"HIGH|MEDIUM|LOW","action":"string","reason":"string"}]}.' .
                    ' Prioritize diagnosed insights from issue_counts, decision_overview, branch metrics, and branch decision_signals.' .
                    ' The summary must state overall branches condition and one forward-risk statement (30-60 day implication) using provided risk signals only.' .
                    ' Use "branches" for overall context and "branch" for single-unit context.' .
                    ' branches is a representative sample when branch_count is large; rely on aggregate fields for portfolio-wide conclusions.' .
                    ' Each recommendation must be specific and branch-grounded, with reason containing at least one numeric metric from input data.' .
                    ' Do not add new topics (customers, staffing, marketing, external market conditions) unless explicitly present in the data.' .
                    ' Keep all numbers and branch names grounded to data/formula_reference; do not invent facts, thresholds, or benchmarks.' .
                    ' If uncertainty exists due to anomaly_flag_count, state it explicitly in reason and prioritize data validation actions.' .
                    ' Data: ' . json_encode($overviewInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $decoded = $this->chatForJson($messages);
        if ($decoded === null) {
            if ($this->lastAiStatus === 'unknown') {
                $this->lastAiStatus = 'provider_error';
            }
            return null;
        }

        $summary = trim((string)($decoded['summary'] ?? ''));
        $recommendations = [];
        if (isset($decoded['recommendations']) && is_array($decoded['recommendations'])) {
            $recommendations = $this->normalizeRecommendations($decoded['recommendations']);
        }

        if ($summary === '' && !$recommendations) {
            $this->lastAiStatus = 'invalid_response';
            $this->lastAiError = 'AI returned empty overview payload.';
            return null;
        }

        $groundingPayload = $this->buildGroundingPayload($overviewInput);
        if (!$this->isNumericallyGrounded([$summary], $groundingPayload)) {
            // Keep overview usable even when wording introduces extra numeric tokens.
            // Narrative anchoring and deterministic fallback still protect output quality.
            $this->lastAiStatus = 'grounding_warning';
            $this->lastAiError = 'AI overview contains non-grounded numeric tokens.';
        }

        if (!$this->isOverviewNarrativeAnchored($summary, $recommendations, $groundingPayload)) {
            // Do not hard-fail overview response on anchor style issues.
            // Keep diagnostics visible so the issue can still be monitored.
            $this->lastAiStatus = 'anchor_warning';
            $this->lastAiError = 'AI overview has weak narrative anchors.';
        }

        return [
            'summary' => $summary,
            'recommendations' => $recommendations,
        ];
    }

    private function buildBranchInterpretationWithAi(array $analysis): ?array
    {
        if (!$this->groqClient instanceof GroqClient) {
            return null;
        }

        $m = $analysis['metrics'];
        $branchInput = [
            'formula_reference' => $this->buildFormulaReference(),
            'branch_name' => $analysis['branch_name'],
            'overall_score' => $analysis['overall_score'],
            'status' => $analysis['status'],
            'status_text' => $analysis['status_text'],
            'previous_sales' => $this->formatMoney($m['previous_sales']),
            'current_sales' => $this->formatMoney($m['current_sales']),
            'sales_delta' => $this->formatMoney($m['current_sales'] - $m['previous_sales']),
            'expenses' => $this->formatMoney($m['expenses']),
            'cogs' => $this->formatMoney($m['cogs']),
            'net_income' => $this->formatMoney($m['net_income']),
            'avg_inventory' => $this->formatMoney($m['avg_inventory']),
            'dead_stock' => $this->formatMoney($m['dead_stock']),
            'sales_growth_rate' => $this->formatPercent($m['sales_growth_rate']),
            'net_margin' => $this->formatPercent($m['net_margin']),
            'expense_ratio' => $this->formatPercent($m['expense_ratio']),
            'dead_stock_ratio' => $this->formatPercent($m['dead_stock_ratio']),
            'actual_pos_days' => (int)$m['actual_pos_days'],
            'expected_pos_days' => (int)$m['expected_pos_days'],
            'pos_compliance_rate' => $this->formatPercent($m['pos_compliance_rate']),
            'factor_scores' => array_map(function ($factor) {
                return [
                    'name' => (string)($factor['name'] ?? ''),
                    'score' => isset($factor['score']) ? (int)$factor['score'] : 0,
                    'weight' => isset($factor['weight']) ? (int)$factor['weight'] : 0,
                    'raw_basis' => (string)($factor['raw_basis'] ?? ''),
                ];
            }, $analysis['factors'] ?? []),
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a franchise operations analyst. Reply with strict JSON only and no markdown. Use only facts from the provided data and formula_reference. Do not invent numbers, thresholds, branch names, or benchmarks.',
            ],
            [
                'role' => 'user',
                'content' =>
                    'Your task is to analyze the performance of a single branch using the provided metrics and generate clear, actionable business insights for management.' .
                    ' Focus on: identifying major problems, highlighting strong areas, explaining unusual data patterns, and suggesting practical actions for branch managers.' .
                    ' Do NOT repeat the numbers excessively. Interpret them like an analyst.' .
                    ' Return JSON with this exact schema: {"interpretation":["string"]}.' .
                    ' Format the interpretation lines to follow this structure:' .
                    ' AI SUMMARY: one short paragraph line about overall performance.' .
                    ' KEY INSIGHTS: 1-2 lines .' .
                    ' RISK FLAGS: 1 lines .' .
                    ' RECOMMENDED ACTION: 1 lines.' .
                    ' Keep all numbers and branch names grounded to data/formula_reference; do not add new facts or new numeric claims.' .
                    ' Do not add new topics (customers, staffing, marketing, external market conditions) unless explicitly present in the data.' .
                    ' If uncertainty exists, choose a conservative interpretation and reference available metrics only.' .
                    ' Data: ' . json_encode($branchInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $decoded = $this->chatForJson($messages);
        if ($decoded === null || !isset($decoded['interpretation']) || !is_array($decoded['interpretation'])) {
            if ($this->lastAiStatus === 'unknown') {
                $this->lastAiStatus = 'invalid_response';
                $this->lastAiError = 'AI branch interpretation payload is missing or invalid.';
            }
            return null;
        }

        $lines = [];
        foreach ($decoded['interpretation'] as $line) {
            if (!is_string($line)) {
                continue;
            }

            $clean = trim($line);
            if ($clean === '') {
                continue;
            }

            $lines[] = $clean;
            if (count($lines) >= 12) {
                break;
            }
        }

        if (!$lines) {
            $this->lastAiStatus = 'invalid_response';
            $this->lastAiError = 'AI returned empty branch interpretation lines.';
            return null;
        }

        $hasMainAction = false;
        foreach ($lines as $line) {
            if (preg_match('/^Main action\s*:/i', $line)) {
                $hasMainAction = true;
                break;
            }
        }
        if (!$hasMainAction) {
            $actions = $this->buildPriorityActionsForBranch($analysis);
            $injected = is_array($actions) && !empty($actions) ? $actions[0] : 'Main action: review branch KPIs weekly.';
            if (count($lines) >= 12) {
                array_pop($lines);
            }
            $lines[] = $injected;
            if ($this->lastAiStatus === 'unknown') {
                $this->lastAiStatus = 'patched';
                $this->lastAiError = 'Injected missing "Main action:" line.';
            }
        }

        $groundingPayload = $this->buildGroundingPayload($branchInput);
        if (!$this->isNumericallyGrounded($lines, $groundingPayload)) {
            $this->lastAiStatus = 'grounding_warning';
            $this->lastAiError = 'AI branch interpretation contains non-grounded numeric tokens.';
        }

        if (!$this->isBranchNarrativeAnchored($lines, $groundingPayload)) {
            $this->lastAiStatus = 'anchor_warning';
            $this->lastAiError = 'AI branch interpretation has weak narrative anchors.';
        }

        return $lines ?: null;
    }

    private function buildMonthlyNarrativeWithAi(array $month, ?array $branchContext = null, ?array $portfolioContext = null): ?array
    {
        if (!$this->groqClient instanceof GroqClient) {
            return null;
        }

        $statusCounts = $this->buildMonthlyStatusCounts($month);
        $branches = (isset($month['branches']) && is_array($month['branches'])) ? $month['branches'] : [];
        $isBranchFocused = is_array($branchContext);
        $monthlyBranchLimit = $this->resolveAiSampleLimit(
            'AI_MONTHLY_BRANCH_SAMPLE_LIMIT',
            $isBranchFocused ? 12 : 18,
            4,
            120
        );
        $branchesForAi = $this->sampleRankedBranchRows($branches, $monthlyBranchLimit);
        $monthInput = [
            'formula_reference' => $this->buildFormulaReference(),
            'forward_risk_window_days' => [30, 60],
            'scope' => $isBranchFocused ? 'selected_branch' : 'overall_branches',
            'month_key' => (string)($month['month_key'] ?? ''),
            'month_label' => (string)($month['month_label'] ?? ''),
            'branch_count' => isset($month['branch_count']) ? (int)$month['branch_count'] : count($branches),
            'branches_sample_size' => count($branchesForAi),
            'average_score' => isset($month['average_score']) ? (int)$month['average_score'] : 0,
            'risk_count' => isset($month['risk_count']) ? (int)$month['risk_count'] : 0,
            'status_counts' => $statusCounts,
            'top_branch' => [
                'branch_name' => (string)($month['top_branch']['branch_name'] ?? ''),
                'overall_score' => isset($month['top_branch']['overall_score']) ? (int)$month['top_branch']['overall_score'] : 0,
                'status' => (string)($month['top_branch']['status'] ?? ''),
            ],
            'bottom_branch' => [
                    'branch_name' => (string)($month['bottom_branch']['branch_name'] ?? ''),
                    'overall_score' => isset($month['bottom_branch']['overall_score']) ? (int)$month['bottom_branch']['overall_score'] : 0,
                    'status' => (string)($month['bottom_branch']['status'] ?? ''),
                ],
                'branches' => array_map(function ($branch) {
                return [
                    'branch_name' => (string)($branch['branch_name'] ?? ''),
                    'overall_score' => isset($branch['overall_score']) ? (int)$branch['overall_score'] : 0,
                        'status' => (string)($branch['status'] ?? ''),
                        'status_text' => (string)($branch['status_text'] ?? ''),
                    ];
                }, $branchesForAi),
        ];
        if ($isBranchFocused) {
            $monthInput['selected_branch'] = $branchContext;
        } elseif (is_array($portfolioContext)) {
            $monthInput['branches_context'] = $this->normalizePortfolioKeysForAi($portfolioContext);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a franchise operations analyst. Reply with strict JSON only and no markdown. Use only facts from the provided data and formula_reference. Do not invent numbers, thresholds, branch names, or benchmarks.',
            ],
            [
                'role' => 'user',
                'content' =>
                    (
                        $isBranchFocused
                            ? (
                                'Generate a detailed monthly branch narrative for selected_branch only.' .
                                ' Return JSON with this exact schema: {"narrative":["string"]}.' .
                                ' Output 6-8 lines, with one line starting with "Main action:".' .
                                ' Include current month score/status, sales movement, profitability, inventory quality, POS compliance, and a forward-risk line using selected_branch.current risk/projection fields.' .
                                ' If selected_branch.previous exists, include a specific previous-month comparison with concrete metric deltas.' .
                                ' Mention only selected_branch.branch_name and do not mention any other branch names.' .
                                ' Use singular branch wording for this narrative.' .
                                ' Keep all numbers and claims strictly grounded to provided data. Do not invent facts.'
                            )
                            : (
                                'Generate a monthly overall branches executive narrative from the provided data.' .
                                ' Return JSON with this exact schema: {"narrative":["string"]}.' .
                                ' Mention the month_label, branch_count, average_score, and both top/bottom branch names.' .
                                ' Include month-over-month movement if branches_context.previous exists, with concrete change values.' .
                                ' Explain drivers behind critical branches using branches_context.critical_branch_drivers.' .
                                ' Include estimated business impact from branches_context.current.total_estimated_impact_php.' .
                                ' Include one concise forward-risk line for the next 30-60 days grounded to the provided month status counts.' .
                                ' Keep at least one line starting with "Main action:".' .
                                ' branches is a representative ranked sample when branch_count is large; rely on branch_count/status_counts for overall totals.' .
                                ' Use "branches" wording for overall context and avoid the word "portfolio".' .
                                ' Do not add topics that are not present in the provided data.' .
                                ' Keep all numbers and branch names grounded to the provided data; do not add new facts.'
                            )
                    ) .
                    ' If uncertain, be conservative and only state metrics directly present in the input.' .
                    ' Data: ' . json_encode($monthInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $decoded = $this->chatForJson($messages);
        if ($decoded === null || !isset($decoded['narrative']) || !is_array($decoded['narrative'])) {
            return null;
        }

        $lines = [];
        foreach ($decoded['narrative'] as $line) {
            if (!is_string($line)) {
                continue;
            }

            $clean = trim($line);
            if ($clean === '') {
                continue;
            }

            $lines[] = $clean;
            if (count($lines) >= ($isBranchFocused ? 12 : 10)) {
                break;
            }
        }

        if (!$lines) {
            return null;
        }

        $hasMainAction = false;
        foreach ($lines as $line) {
            if (preg_match('/^Main action\s*:/i', $line)) {
                $hasMainAction = true;
                break;
            }
        }
        if (!$hasMainAction) {
            return null;
        }

        $groundingPayload = $this->buildGroundingPayload($monthInput);
        if (!$this->isNumericallyGrounded($lines, $groundingPayload)) {
            $this->lastAiStatus = 'grounding_warning';
            $this->lastAiError = 'AI monthly narrative contains non-grounded numeric tokens.';
        }

        if (
            $isBranchFocused
                ? !$this->isBranchMonthlyNarrativeAnchored($lines, $groundingPayload)
                : !$this->isMonthlyNarrativeAnchored($lines, $groundingPayload)
        ) {
            $this->lastAiStatus = 'anchor_warning';
            $this->lastAiError = 'AI monthly narrative has weak narrative anchors.';
        }

        return $lines ?: null;
    }

    private function buildYearlyNarrativeWithAi(array $year, ?array $previousYear = null, bool $isBranchFocused = false): ?array
    {
        if (!$this->groqClient instanceof GroqClient) {
            return null;
        }

        $statusCounts = $this->buildMonthlyStatusCounts($year);
        $branches = (isset($year['branches']) && is_array($year['branches'])) ? $year['branches'] : [];
        $yearlyBranchLimit = $this->resolveAiSampleLimit(
            'AI_YEARLY_BRANCH_SAMPLE_LIMIT',
            $isBranchFocused ? 12 : 18,
            4,
            120
        );
        $branchesForAi = $this->sampleRankedBranchRows($branches, $yearlyBranchLimit);
        $yearInput = [
            'formula_reference' => $this->buildFormulaReference(),
            'scope' => $isBranchFocused ? 'selected_branch' : 'overall_branches',
            'year_key' => (string)($year['year_key'] ?? ''),
            'year_label' => (string)($year['year_label'] ?? ''),
            'branch_count' => isset($year['branch_count']) ? (int)$year['branch_count'] : count($branches),
            'branches_sample_size' => count($branchesForAi),
            'average_score' => isset($year['average_score']) ? (int)$year['average_score'] : 0,
            'risk_count' => isset($year['risk_count']) ? (int)$year['risk_count'] : 0,
            'status_counts' => $statusCounts,
            'top_branch' => [
                'branch_name' => (string)($year['top_branch']['branch_name'] ?? ''),
                'overall_score' => isset($year['top_branch']['overall_score']) ? (int)$year['top_branch']['overall_score'] : 0,
                'status' => (string)($year['top_branch']['status'] ?? ''),
            ],
            'bottom_branch' => [
                'branch_name' => (string)($year['bottom_branch']['branch_name'] ?? ''),
                'overall_score' => isset($year['bottom_branch']['overall_score']) ? (int)$year['bottom_branch']['overall_score'] : 0,
                'status' => (string)($year['bottom_branch']['status'] ?? ''),
            ],
            'branches' => array_map(function ($branch) {
                return [
                    'branch_name' => (string)($branch['branch_name'] ?? ''),
                    'overall_score' => isset($branch['overall_score']) ? (int)$branch['overall_score'] : 0,
                    'status' => (string)($branch['status'] ?? ''),
                    'status_text' => (string)($branch['status_text'] ?? ''),
                    'sample_count' => isset($branch['sample_count']) ? (int)$branch['sample_count'] : 0,
                ];
            }, $branchesForAi),
        ];

        if (is_array($previousYear)) {
            $yearInput['previous_year'] = [
                'year_key' => (string)($previousYear['year_key'] ?? ''),
                'year_label' => (string)($previousYear['year_label'] ?? ''),
                'branch_count' => isset($previousYear['branch_count']) ? (int)$previousYear['branch_count'] : 0,
                'average_score' => isset($previousYear['average_score']) ? (int)$previousYear['average_score'] : 0,
                'risk_count' => isset($previousYear['risk_count']) ? (int)$previousYear['risk_count'] : 0,
                'top_branch' => [
                    'branch_name' => (string)($previousYear['top_branch']['branch_name'] ?? ''),
                    'overall_score' => isset($previousYear['top_branch']['overall_score']) ? (int)$previousYear['top_branch']['overall_score'] : 0,
                ],
                'bottom_branch' => [
                    'branch_name' => (string)($previousYear['bottom_branch']['branch_name'] ?? ''),
                    'overall_score' => isset($previousYear['bottom_branch']['overall_score']) ? (int)$previousYear['bottom_branch']['overall_score'] : 0,
                ],
            ];
            $yearInput['delta_vs_previous'] = [
                'average_score_points' => ((int)($year['average_score'] ?? 0)) - ((int)($previousYear['average_score'] ?? 0)),
                'risk_count' => ((int)($year['risk_count'] ?? 0)) - ((int)($previousYear['risk_count'] ?? 0)),
                'branch_count' => ((int)($year['branch_count'] ?? 0)) - ((int)($previousYear['branch_count'] ?? 0)),
            ];
        }

        if ($isBranchFocused) {
            $selectedBranch = null;
            if (!empty($branches) && is_array($branches[0])) {
                $selectedBranch = $branches[0];
            } elseif (isset($year['top_branch']) && is_array($year['top_branch'])) {
                $selectedBranch = $year['top_branch'];
            }

            if (is_array($selectedBranch)) {
                $branchName = (string)($selectedBranch['branch_name'] ?? '');
                $yearInput['selected_branch'] = [
                    'branch_name' => $branchName,
                    'overall_score' => isset($selectedBranch['overall_score']) ? (int)$selectedBranch['overall_score'] : 0,
                    'status' => (string)($selectedBranch['status'] ?? ''),
                    'status_text' => (string)($selectedBranch['status_text'] ?? ''),
                    'sample_count' => isset($selectedBranch['sample_count']) ? (int)$selectedBranch['sample_count'] : 0,
                ];

                if (is_array($previousYear)) {
                    $previousBranch = $this->findYearBranchByName($previousYear, $branchName);
                    if (is_array($previousBranch)) {
                        $yearInput['selected_branch_previous'] = [
                            'branch_name' => (string)($previousBranch['branch_name'] ?? ''),
                            'overall_score' => isset($previousBranch['overall_score']) ? (int)$previousBranch['overall_score'] : 0,
                            'status' => (string)($previousBranch['status'] ?? ''),
                            'status_text' => (string)($previousBranch['status_text'] ?? ''),
                            'sample_count' => isset($previousBranch['sample_count']) ? (int)$previousBranch['sample_count'] : 0,
                        ];
                        $yearInput['selected_branch_delta'] = [
                            'score_points' => ((int)($selectedBranch['overall_score'] ?? 0)) - ((int)($previousBranch['overall_score'] ?? 0)),
                        ];
                    }
                }
            }
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a franchise operations analyst. Reply with strict JSON only and no markdown. Use only facts from the provided data and formula_reference. Do not invent numbers, thresholds, branch names, or benchmarks.',
            ],
            [
                'role' => 'user',
                'content' =>
                    (
                        $isBranchFocused
                            ? (
                                'Generate a detailed yearly branch narrative for selected_branch only.' .
                                ' Return JSON with this exact schema: {"narrative":["string"]}.' .
                                ' Output 5-7 lines, with one line starting with "Main action:".' .
                                ' Include year score/status and sample_count (number of months summarized).' .
                                ' If selected_branch_previous exists, include year-over-year score/status change with concrete values.' .
                                ' Mention only selected_branch.branch_name and do not mention any other branch names.' .
                                ' Keep all numbers and claims strictly grounded to provided data. Do not invent facts.'
                            )
                            : (
                                'Generate a yearly overall branches executive narrative from the provided data.' .
                                ' Return JSON with this exact schema: {"narrative":["string"]}.' .
                                ' Mention year_label, branch_count, average_score, risk_count, and both top/bottom branch names.' .
                                ' If previous_year exists, include year-over-year changes using delta_vs_previous with concrete values.' .
                                ' Include one line with a concise risk outlook and one line starting with "Main action:".' .
                                ' branches is a representative ranked sample when branch_count is large; rely on branch_count/status_counts for overall totals.' .
                                ' Use "branches" wording for overall context and avoid the word "portfolio".' .
                                ' Keep all numbers and branch names grounded to the provided data; do not add new facts.'
                            )
                    ) .
                    ' If uncertain, be conservative and only state metrics directly present in the input.' .
                    ' Data: ' . json_encode($yearInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $decoded = $this->chatForJson($messages);
        if ($decoded === null || !isset($decoded['narrative']) || !is_array($decoded['narrative'])) {
            return null;
        }

        $lines = [];
        foreach ($decoded['narrative'] as $line) {
            if (!is_string($line)) {
                continue;
            }

            $clean = trim($line);
            if ($clean === '') {
                continue;
            }

            $lines[] = $clean;
            if (count($lines) >= ($isBranchFocused ? 10 : 9)) {
                break;
            }
        }

        if (!$lines) {
            return null;
        }

        $hasMainAction = false;
        foreach ($lines as $line) {
            if (preg_match('/^Main action\s*:/i', $line)) {
                $hasMainAction = true;
                break;
            }
        }
        if (!$hasMainAction) {
            return null;
        }

        $groundingPayload = $this->buildGroundingPayload($yearInput);
        if (!$this->isNumericallyGrounded($lines, $groundingPayload)) {
            $this->lastAiStatus = 'grounding_warning';
            $this->lastAiError = 'AI yearly narrative contains non-grounded numeric tokens.';
        }

        return $lines ?: null;
    }

    private function chatForJson(array $messages): ?array
    {
        if (!$this->groqClient instanceof GroqClient) {
            $this->lastAiStatus = 'disabled';
            $this->lastAiError = 'Groq client is not configured.';
            return null;
        }
        if ($this->isAiRateLimitCooldownActive()) {
            return null;
        }

        $attemptMessages = $messages;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->groqClient->chat($attemptMessages, $attempt === 0 ? 0.1 : 0.0, true);
            if (!is_array($response) || !empty($response['error'])) {
                if (is_array($response) && !empty($response['error'])) {
                    $this->markAiError((string)$response['error']);
                    if ($this->lastAiStatus === 'rate_limited') {
                        return null;
                    }
                }
                if ($attempt === 0) {
                    // First fallback in case provider rejects JSON mode.
                    $response = $this->groqClient->chat($attemptMessages, 0.1, false);
                    if (!is_array($response) || !empty($response['error'])) {
                        if (is_array($response) && !empty($response['error'])) {
                            $this->markAiError((string)$response['error']);
                            if ($this->lastAiStatus === 'rate_limited') {
                                return null;
                            }
                        }
                        continue;
                    }
                } else {
                    continue;
                }
            }

            $content = trim((string)($response['content'] ?? ''));
            if ($content === '') {
                $this->lastAiStatus = 'invalid_response';
                $this->lastAiError = 'AI returned empty content.';
                $attemptMessages[] = [
                    'role' => 'user',
                    'content' => 'Reply with one valid JSON object only. No markdown. No explanation.',
                ];
                continue;
            }

            $decoded = $this->extractJsonObject($content);
            if (is_array($decoded)) {
                $this->lastAiStatus = 'ok';
                $this->lastAiError = null;
                return $decoded;
            }

            $this->lastAiStatus = 'invalid_response';
            $this->lastAiError = 'AI returned non-JSON content.';

            $attemptMessages[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
            $attemptMessages[] = [
                'role' => 'user',
                'content' => 'Your previous reply was invalid JSON. Return the same answer as one valid JSON object only. No markdown. No extra text.',
            ];
        }

        return null;
    }

    private function resetAiDiagnostics(): void
    {
        $this->lastAiStatus = 'unknown';
        $this->lastAiError = null;
    }

    private function markAiError(string $error): void
    {
        $message = trim($error);
        $this->lastAiError = $message !== '' ? $message : 'Unknown AI error.';

        $haystack = strtolower($this->lastAiError);
        if (
            strpos($haystack, 'context') !== false ||
            strpos($haystack, 'context window') !== false ||
            strpos($haystack, 'prompt is too long') !== false ||
            strpos($haystack, 'too many tokens') !== false ||
            strpos($haystack, 'maximum context length') !== false ||
            strpos($haystack, 'max tokens') !== false ||
            strpos($haystack, 'input too long') !== false
        ) {
            $this->lastAiStatus = 'context_limit';
            return;
        }
        if (
            strpos($haystack, '429') !== false ||
            strpos($haystack, 'rate limit') !== false ||
            strpos($haystack, 'too many requests') !== false
        ) {
            $this->lastAiStatus = 'rate_limited';
            $retryAfterSeconds = $this->extractRateLimitRetryAfterSeconds($this->lastAiError);
            if ($retryAfterSeconds === null) {
                $retryAfterSeconds = $this->resolveRateLimitCooldownSeconds();
            }
            $this->persistAiRateLimitCooldown($retryAfterSeconds, $this->lastAiError);
            return;
        }
        if (strpos($haystack, 'api key') !== false || strpos($haystack, 'not set') !== false) {
            $this->lastAiStatus = 'disabled';
            return;
        }
        if (
            strpos($haystack, 'timeout') !== false ||
            strpos($haystack, 'curl error') !== false ||
            strpos($haystack, 'connection') !== false
        ) {
            $this->lastAiStatus = 'upstream_unavailable';
            return;
        }

        $this->lastAiStatus = 'provider_error';
    }

    private function isAiRateLimitCooldownActive(): bool
    {
        $payload = $this->readAiRateLimitCooldown();
        if (!is_array($payload)) {
            return false;
        }

        $blockedUntil = isset($payload['blocked_until']) ? (int)$payload['blocked_until'] : 0;
        if ($blockedUntil <= 0) {
            return false;
        }

        $now = time();
        if ($blockedUntil <= $now) {
            $this->clearAiRateLimitCooldown();
            return false;
        }

        $remaining = $blockedUntil - $now;
        $this->lastAiStatus = 'rate_limited';
        if ($this->lastAiError === null || trim((string)$this->lastAiError) === '') {
            $this->lastAiError = sprintf(
                'AI rate-limit cooldown active (%ds remaining).',
                $remaining
            );
        }

        return true;
    }

    private function extractRateLimitRetryAfterSeconds(string $message): ?int
    {
        $clean = trim($message);
        if ($clean === '') {
            return null;
        }

        if (preg_match('/try again in\s*(\d+)\s*m\s*([\d.]+)\s*s/i', $clean, $match)) {
            $minutes = isset($match[1]) ? (int)$match[1] : 0;
            $seconds = isset($match[2]) ? (float)$match[2] : 0.0;
            return max(1, ($minutes * 60) + (int)ceil($seconds));
        }

        if (preg_match('/try again in\s*([\d.]+)\s*s/i', $clean, $match)) {
            return max(1, (int)ceil((float)$match[1]));
        }

        if (preg_match('/retry after\s*([\d.]+)\s*s/i', $clean, $match)) {
            return max(1, (int)ceil((float)$match[1]));
        }

        return null;
    }

    private function resolveRateLimitCooldownSeconds(): int
    {
        $raw = trim($this->readEnv('AI_RATE_LIMIT_COOLDOWN_SECONDS', '240'));
        $value = (int)$raw;
        if ($value <= 0) {
            $value = 240;
        }

        return max(30, min($value, 3600));
    }

    private function persistAiRateLimitCooldown(int $seconds, string $reason = ''): void
    {
        $safeSeconds = max(30, min($seconds, 3600));
        $payload = [
            'blocked_until' => time() + $safeSeconds,
            'updated_at' => gmdate('c'),
            'reason' => trim($reason),
        ];
        $path = $this->resolveAiRateLimitStatePath();
        if ($path === '') {
            return;
        }

        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function readAiRateLimitCooldown(): ?array
    {
        $path = $this->resolveAiRateLimitStatePath();
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function clearAiRateLimitCooldown(): void
    {
        $path = $this->resolveAiRateLimitStatePath();
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function resolveAiRateLimitStatePath(): string
    {
        $custom = trim($this->readEnv('AI_RATE_LIMIT_STATE_FILE', ''));
        if ($custom !== '') {
            return $custom;
        }

        $tempDir = rtrim((string)sys_get_temp_dir(), '\\/');
        if ($tempDir === '') {
            return '';
        }

        return $tempDir . DIRECTORY_SEPARATOR . 'fh_ai_rate_limit_state.json';
    }

    private function resolveFallbackAiStatus(): string
    {
        if (!$this->groqClient instanceof GroqClient) {
            return 'disabled';
        }
        if ($this->lastAiStatus === 'unknown' || $this->lastAiStatus === 'ok') {
            return 'validation_rejected';
        }
        return $this->lastAiStatus;
    }

    private function extractJsonObject(string $content): ?array
    {
        $clean = trim($content);
        if ($clean === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $clean, $match)) {
            $clean = trim((string)$match[1]);
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $candidate = substr($clean, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeRecommendations(array $recommendations): array
    {
        $normalized = [];
        $seen = [];
        foreach ($recommendations as $item) {
            if (!is_array($item)) {
                continue;
            }

            $priority = strtoupper(trim((string)($item['priority'] ?? 'MEDIUM')));
            if (!in_array($priority, ['HIGH', 'MEDIUM', 'LOW'], true)) {
                $priority = 'MEDIUM';
            }

            $action = trim((string)($item['action'] ?? ''));
            if ($action === '') {
                continue;
            }

            $reason = trim((string)($item['reason'] ?? ''));
            if ($reason === '') {
                continue;
            }

            $fingerprint = strtolower($action . '|' . $reason);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $normalized[] = [
                'priority' => $priority,
                'action' => $action,
                'reason' => $reason,
            ];

            if (count($normalized) >= 5) {
                break;
            }
        }

        return $normalized;
    }

    private function resolveAiSampleLimit(string $envKey, int $default, int $min = 4, int $max = 200): int
    {
        $fallback = max($min, min($default, $max));
        $raw = trim($this->readEnv($envKey, ''));
        if ($raw === '') {
            return $fallback;
        }

        $value = (int)$raw;
        if ($value <= 0) {
            return $fallback;
        }

        return max($min, min($value, $max));
    }

    private function sampleRankedBranchRows(array $branches, int $limit): array
    {
        $rows = [];
        foreach ($branches as $branch) {
            if (is_array($branch)) {
                $rows[] = $branch;
            }
        }

        if ($limit <= 0 || count($rows) <= $limit) {
            return $rows;
        }

        $headCount = (int)ceil($limit / 2);
        $tailCount = max(0, $limit - $headCount);
        $sample = [];
        $seen = [];

        foreach (array_slice($rows, 0, $headCount) as $branch) {
            $name = strtolower(trim((string)($branch['branch_name'] ?? '')));
            if ($name === '') {
                $name = md5(serialize($branch));
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $sample[] = $branch;
        }

        if ($tailCount > 0) {
            foreach (array_slice($rows, -$tailCount) as $branch) {
                $name = strtolower(trim((string)($branch['branch_name'] ?? '')));
                if ($name === '') {
                    $name = md5(serialize($branch));
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $sample[] = $branch;
                if (count($sample) >= $limit) {
                    break;
                }
            }
        }

        if (count($sample) < $limit) {
            foreach ($rows as $branch) {
                $name = strtolower(trim((string)($branch['branch_name'] ?? '')));
                if ($name === '') {
                    $name = md5(serialize($branch));
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $sample[] = $branch;
                if (count($sample) >= $limit) {
                    break;
                }
            }
        }

        return array_values(array_slice($sample, 0, $limit));
    }

    private function buildOverviewAiBranchRows(array $analysis): array
    {
        $limit = $this->resolveAiSampleLimit('AI_OVERVIEW_BRANCH_SAMPLE_LIMIT', 18, 6, 120);
        $sample = $this->pickOverviewAnalysisSample($analysis, $limit);

        $rows = [];
        foreach ($sample as $item) {
            if (!is_array($item)) {
                continue;
            }

            $metrics = (isset($item['metrics']) && is_array($item['metrics'])) ? $item['metrics'] : [];
            $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                ? $item['decision_signals']
                : [];

            $rows[] = [
                'branch_name' => (string)($item['branch_name'] ?? ''),
                'overall_score' => isset($item['overall_score']) ? (int)$item['overall_score'] : 0,
                'status' => (string)($item['status'] ?? ''),
                'sales_growth_rate' => $this->formatPercent($metrics['sales_growth_rate'] ?? null),
                'net_margin' => $this->formatPercent($metrics['net_margin'] ?? null),
                'expense_ratio' => $this->formatPercent($metrics['expense_ratio'] ?? null),
                'dead_stock_ratio' => $this->formatPercent($metrics['dead_stock_ratio'] ?? null),
                'pos_compliance_rate' => $this->formatPercent($metrics['pos_compliance_rate'] ?? null),
                'severity_score' => isset($signals['severity_score']) ? (int)$signals['severity_score'] : 0,
                'estimated_impact_php' => $this->formatMoney((float)($signals['estimated_impact_php'] ?? 0.0)),
                'risk_30d_level' => (string)($signals['risk_30d']['level'] ?? 'LOW'),
                'risk_60d_level' => (string)($signals['risk_60d']['level'] ?? 'LOW'),
            ];
        }

        return $rows;
    }

    private function pickOverviewAnalysisSample(array $analysis, int $limit): array
    {
        $rows = [];
        foreach ($analysis as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        if ($limit <= 0 || count($rows) <= $limit) {
            return $rows;
        }

        $risk = $rows;
        usort($risk, function (array $a, array $b): int {
            $aSeverity = isset($a['decision_signals']['severity_score']) ? (int)$a['decision_signals']['severity_score'] : 0;
            $bSeverity = isset($b['decision_signals']['severity_score']) ? (int)$b['decision_signals']['severity_score'] : 0;
            if ($aSeverity !== $bSeverity) {
                return $bSeverity <=> $aSeverity;
            }

            $aScore = isset($a['overall_score']) ? (int)$a['overall_score'] : 0;
            $bScore = isset($b['overall_score']) ? (int)$b['overall_score'] : 0;
            return $aScore <=> $bScore;
        });

        $leaders = $rows;
        usort($leaders, function (array $a, array $b): int {
            $aScore = isset($a['overall_score']) ? (int)$a['overall_score'] : 0;
            $bScore = isset($b['overall_score']) ? (int)$b['overall_score'] : 0;
            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }

            $aSeverity = isset($a['decision_signals']['severity_score']) ? (int)$a['decision_signals']['severity_score'] : 0;
            $bSeverity = isset($b['decision_signals']['severity_score']) ? (int)$b['decision_signals']['severity_score'] : 0;
            return $aSeverity <=> $bSeverity;
        });

        $picked = [];
        $seen = [];
        $riskQuota = max(1, (int)ceil($limit * 0.7));

        foreach ($risk as $item) {
            $key = strtolower(trim((string)($item['branch_name'] ?? '')));
            if ($key === '') {
                $key = md5(serialize($item));
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $picked[] = $item;
            if (count($picked) >= $riskQuota) {
                break;
            }
        }

        foreach ($leaders as $item) {
            if (count($picked) >= $limit) {
                break;
            }
            $key = strtolower(trim((string)($item['branch_name'] ?? '')));
            if ($key === '') {
                $key = md5(serialize($item));
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $picked[] = $item;
        }

        if (count($picked) < $limit) {
            foreach ($rows as $item) {
                if (count($picked) >= $limit) {
                    break;
                }
                $key = strtolower(trim((string)($item['branch_name'] ?? '')));
                if ($key === '') {
                    $key = md5(serialize($item));
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $picked[] = $item;
            }
        }

        return array_values(array_slice($picked, 0, $limit));
    }

    private function buildFormulaReference(): array
    {
        return [
            'kpi_formulae' => [
                'sales_growth_rate' => '(current_sales - previous_sales) / previous_sales',
                'net_income' => 'current_sales - expenses - cogs',
                'net_margin' => '(current_sales - expenses - cogs) / current_sales',
                'expense_ratio' => 'expenses / current_sales',
                'dead_stock_ratio' => 'dead_stock / avg_inventory',
                'pos_compliance_rate' => 'actual_pos_days / expected_pos_days',
            ],
            'targets' => [
                'sales_growth_target' => $this->formatPercent(self::SALES_GROWTH_TARGET),
                'net_margin_target' => $this->formatPercent(self::NET_MARGIN_TARGET),
                'expense_ratio_limit' => $this->formatPercent(self::EXPENSE_RATIO_LIMIT),
                'dead_stock_ratio_limit' => $this->formatPercent(self::DEAD_STOCK_RATIO_LIMIT),
                'pos_compliance_target' => $this->formatPercent(self::POS_COMPLIANCE_TARGET),
            ],
            'health_score_weights' => [
                'sales_performance' => 30,
                'net_income' => 25,
                'inventory_health' => 20,
                'expense_control' => 15,
                'activity_compliance' => 10,
            ],
            'status_min_scores' => [
                'EXCELLENT' => 90,
                'GOOD' => 80,
                'WARNING' => 60,
                'CRITICAL' => 0,
            ],
        ];
    }

    private function recommendationsToText(array $recommendations): string
    {
        $chunks = [];
        foreach ($recommendations as $item) {
            if (!is_array($item)) {
                continue;
            }

            $priority = trim((string)($item['priority'] ?? ''));
            $action = trim((string)($item['action'] ?? ''));
            $reason = trim((string)($item['reason'] ?? ''));

            $line = trim($priority . ' ' . $action . ' ' . $reason);
            if ($line !== '') {
                $chunks[] = $line;
            }
        }

        return implode(' ', $chunks);
    }

    private function isNumericallyGrounded(array $texts, array $referencePayload): bool
    {
        $allowedTokens = $this->collectAllowedNumericTokens($referencePayload);
        if (!$allowedTokens) {
            return true;
        }

        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }

            $tokens = $this->extractNumericTokensFromText($text);
            foreach ($tokens as $token) {
                $canonical = $this->canonicalizeNumericToken($token);
                if ($canonical === null) {
                    continue;
                }

                if (!isset($allowedTokens[$canonical])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function collectAllowedNumericTokens($value): array
    {
        $set = [];
        $this->appendAllowedNumericTokens($set, $value);
        return $set;
    }

    private function appendAllowedNumericTokens(array &$set, $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->appendAllowedNumericTokens($set, $item);
            }
            return;
        }

        if (is_int($value) || is_float($value)) {
            $this->addAllowedNumericToken($set, (string)$value);
            return;
        }

        if (!is_string($value)) {
            return;
        }

        foreach ($this->extractNumericTokensFromText($value) as $token) {
            $this->addAllowedNumericToken($set, $token);
        }
    }

    private function addAllowedNumericToken(array &$set, string $token): void
    {
        $canonical = $this->canonicalizeNumericToken($token);
        if ($canonical === null) {
            return;
        }

        $set[$canonical] = true;

        // Accept both "95%" and "95" when source data is percentage-formatted.
        if (substr($canonical, -1) === '%') {
            $set[rtrim($canonical, '%')] = true;
        }
    }

    private function extractNumericTokensFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (!preg_match_all('/-?\d[\d,]*(?:\.\d+)?%?/', $text, $matches)) {
            return [];
        }

        return $matches[0] ?? [];
    }

    private function canonicalizeNumericToken(string $token): ?string
    {
        $clean = trim($token);
        $clean = trim($clean, " \t\n\r\0\x0B.,;:()[]{}");
        if ($clean === '') {
            return null;
        }

        $isPercent = false;
        if (substr($clean, -1) === '%') {
            $isPercent = true;
            $clean = substr($clean, 0, -1);
        }

        $clean = str_replace(',', '', $clean);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $clean)) {
            return null;
        }

        $num = (float)$clean;
        if (abs($num - round($num)) < 0.000001) {
            $normalized = (string)(int)round($num);
        } else {
            $normalized = rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');
        }

        return $isPercent ? ($normalized . '%') : $normalized;
    }

    private function buildGroundingPayload(array $input): array
    {
        $payload = $input;
        unset($payload['formula_reference']);
        return $payload;
    }

    private function normalizePortfolioKeysForAi(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? str_replace('portfolio', 'branches', $key) : $key;
            $normalized[$normalizedKey] = is_array($value)
                ? $this->normalizePortfolioKeysForAi($value)
                : $value;
        }

        return $normalized;
    }

    private function isOverviewNarrativeAnchored(string $summary, array $recommendations, array $groundingPayload): bool
    {
        $summary = trim($summary);
        if ($summary === '') {
            return false;
        }

        // Keep this validator tolerant; strict hallucination control is handled by numeric grounding.
        if (count($this->extractNumericTokensFromText($summary)) < 1) {
            return false;
        }

        $hasIssues = $this->hasAnyIssueCount($groundingPayload);

        if (!$recommendations) {
            return true;
        }

        foreach ($recommendations as $item) {
            if (!is_array($item)) {
                continue;
            }

            $action = trim((string)($item['action'] ?? ''));
            $reason = trim((string)($item['reason'] ?? ''));
            $line = trim($action . ' ' . $reason);
            if ($line === '') {
                return false;
            }

            if ($hasIssues && !$this->containsAnyNumericToken($reason)) {
                return false;
            }
        }

        return true;
    }

    private function isBranchNarrativeAnchored(array $lines, array $groundingPayload): bool
    {
        $text = trim(implode(' ', $lines));
        if ($text === '') {
            return false;
        }

        $branchName = trim((string)($groundingPayload['branch_name'] ?? ''));
        if ($branchName !== '' && stripos($text, $branchName) === false) {
            return false;
        }

        $status = trim((string)($groundingPayload['status'] ?? ''));
        if ($status !== '' && stripos($text, $status) === false) {
            return false;
        }

        $score = isset($groundingPayload['overall_score']) ? (string)$groundingPayload['overall_score'] : '';
        if ($score !== '' && stripos($text, $score) === false) {
            return false;
        }

        $metricKeys = [
            'sales_growth_rate',
            'net_margin',
            'expense_ratio',
            'dead_stock_ratio',
            'pos_compliance_rate',
        ];
        $metricTokens = [];
        foreach ($metricKeys as $key) {
            $value = trim((string)($groundingPayload[$key] ?? ''));
            if ($value !== '' && strtolower($value) !== 'n/a') {
                $metricTokens[] = $value;
            }
        }

        $metricHits = 0;
        foreach ($metricTokens as $token) {
            if (stripos($text, $token) !== false) {
                $metricHits++;
            }
        }
        if ($metricTokens && $metricHits < min(3, count($metricTokens))) {
            return false;
        }

        if ($this->mentionsOtherKnownBranch($text, $branchName)) {
            return false;
        }

        return true;
    }

    private function isMonthlyNarrativeAnchored(array $lines, array $groundingPayload): bool
    {
        $text = trim(implode(' ', $lines));
        if ($text === '') {
            return false;
        }

        $monthLabel = trim((string)($groundingPayload['month_label'] ?? ''));
        $monthKey = trim((string)($groundingPayload['month_key'] ?? ''));
        if ($monthLabel !== '' && stripos($text, $monthLabel) === false) {
            if ($monthKey === '' || stripos($text, $monthKey) === false) {
                return false;
            }
        }

        $branchCount = isset($groundingPayload['branch_count']) ? (string)$groundingPayload['branch_count'] : '';
        if ($branchCount !== '' && stripos($text, $branchCount) === false) {
            return false;
        }

        $averageScore = isset($groundingPayload['average_score']) ? (string)$groundingPayload['average_score'] : '';
        if ($averageScore !== '' && stripos($text, $averageScore) === false) {
            return false;
        }

        $topName = trim((string)($groundingPayload['top_branch']['branch_name'] ?? ''));
        $bottomName = trim((string)($groundingPayload['bottom_branch']['branch_name'] ?? ''));
        if ($topName !== '' && stripos($text, $topName) === false) {
            return false;
        }
        if ($bottomName !== '' && stripos($text, $bottomName) === false) {
            return false;
        }

        $mainActionPresent = false;
        foreach ($lines as $line) {
            if (preg_match('/^Main action\s*:/i', (string)$line)) {
                $mainActionPresent = true;
                break;
            }
        }

        if (!$mainActionPresent) {
            return false;
        }

        return true;
    }

    private function isBranchMonthlyNarrativeAnchored(array $lines, array $groundingPayload): bool
    {
        $text = trim(implode(' ', $lines));
        if ($text === '') {
            return false;
        }

        $monthLabel = trim((string)($groundingPayload['month_label'] ?? ''));
        $monthKey = trim((string)($groundingPayload['month_key'] ?? ''));
        if ($monthLabel !== '' && stripos($text, $monthLabel) === false) {
            if ($monthKey === '' || stripos($text, $monthKey) === false) {
                return false;
            }
        }

        $selectedBranch = (isset($groundingPayload['selected_branch']) && is_array($groundingPayload['selected_branch']))
            ? $groundingPayload['selected_branch']
            : [];
        $branchName = trim((string)($selectedBranch['branch_name'] ?? ''));
        if ($branchName !== '' && stripos($text, $branchName) === false) {
            return false;
        }

        $current = (isset($selectedBranch['current']) && is_array($selectedBranch['current']))
            ? $selectedBranch['current']
            : [];
        $score = isset($current['overall_score']) ? (string)$current['overall_score'] : '';
        if ($score !== '' && stripos($text, $score) === false) {
            return false;
        }

        $status = trim((string)($current['status'] ?? ''));
        if ($status !== '' && stripos($text, $status) === false) {
            return false;
        }

        if (count($this->extractNumericTokensFromText($text)) < 5) {
            return false;
        }

        $mainActionPresent = false;
        foreach ($lines as $line) {
            if (preg_match('/^Main action\s*:/i', (string)$line)) {
                $mainActionPresent = true;
                break;
            }
        }
        if (!$mainActionPresent) {
            return false;
        }

        $previous = (isset($selectedBranch['previous']) && is_array($selectedBranch['previous']))
            ? $selectedBranch['previous']
            : null;
        if ($previous) {
            $previousMonthLabel = trim((string)($previous['month_label'] ?? ''));
            $previousMonthKey = trim((string)($previous['month_key'] ?? ''));
            $mentionsPreviousMonth =
                ($previousMonthLabel !== '' && stripos($text, $previousMonthLabel) !== false) ||
                ($previousMonthKey !== '' && stripos($text, $previousMonthKey) !== false) ||
                stripos($text, 'previous month') !== false ||
                stripos($text, 'prior month') !== false;
            if (!$mentionsPreviousMonth) {
                return false;
            }
        }

        if ($this->mentionsOtherKnownBranch($text, $branchName)) {
            return false;
        }

        return true;
    }

    private function containsAnyNumericToken(string $text): bool
    {
        return count($this->extractNumericTokensFromText($text)) > 0;
    }

    private function containsAnyLabel(string $text, array $labels): bool
    {
        foreach ($labels as $label) {
            $candidate = trim((string)$label);
            if ($candidate === '') {
                continue;
            }
            if (stripos($text, $candidate) !== false) {
                return true;
            }
        }
        return false;
    }

    private function hasAnyIssueCount(array $groundingPayload): bool
    {
        if (!isset($groundingPayload['issue_counts']) || !is_array($groundingPayload['issue_counts'])) {
            return false;
        }

        foreach ($groundingPayload['issue_counts'] as $value) {
            if ((int)$value > 0) {
                return true;
            }
        }

        return false;
    }

    private function extractBranchNamesFromPayload(array $groundingPayload): array
    {
        $names = [];

        if (isset($groundingPayload['branches']) && is_array($groundingPayload['branches'])) {
            foreach ($groundingPayload['branches'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }

                $name = trim((string)($branch['branch_name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $topName = trim((string)($groundingPayload['top_branch']['branch_name'] ?? ''));
        $bottomName = trim((string)($groundingPayload['bottom_branch']['branch_name'] ?? ''));
        if ($topName !== '') {
            $names[] = $topName;
        }
        if ($bottomName !== '') {
            $names[] = $bottomName;
        }

        $names = array_values(array_unique($names));
        return $names;
    }

    private function mentionsOtherKnownBranch(string $text, string $currentBranch): bool
    {
        foreach ($this->getKnownBranchNames() as $branchName) {
            if ($currentBranch !== '' && strcasecmp($branchName, $currentBranch) === 0) {
                continue;
            }

            if (stripos($text, $branchName) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getKnownBranchNames(): array
    {
        if (is_array($this->knownBranchNamesCache)) {
            return $this->knownBranchNamesCache;
        }

        $names = [];
        foreach ($this->branchRepository->getBranches() as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $name = trim((string)($branch['branch'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $this->knownBranchNamesCache = array_values(array_unique($names));
        return $this->knownBranchNamesCache;
    }

    private function buildPortfolioAnalysis(array $analysis): array
    {
        $branchCount = count($analysis);
        if ($branchCount === 0) {
            return [
                'branch_count' => 0,
                'branches' => [],
                'status_counts' => [],
                'average_score' => 0.0,
                'portfolio_growth' => null,
                'portfolio_net_margin' => null,
                'portfolio_expense_ratio' => null,
                'portfolio_dead_stock_ratio' => null,
                'portfolio_pos_compliance' => null,
                'issue_buckets' => [],
                'top_branch' => null,
                'bottom_branch' => null,
                'under_80' => [],
                'decision_overview' => [
                    'anomaly_branch_count' => 0,
                    'high_risk_branch_count' => 0,
                    'total_estimated_impact_php' => 0.0,
                    'average_severity_score' => 0.0,
                    'average_confidence_score' => 0.0,
                ],
            ];
        }

        $statusCounts = [
            'EXCELLENT' => 0,
            'GOOD' => 0,
            'WARNING' => 0,
            'CRITICAL' => 0,
        ];

        $scoreTotal = 0.0;

        $totals = [
            'current_sales' => 0.0,
            'previous_sales' => 0.0,
            'expenses' => 0.0,
            'cogs' => 0.0,
            'avg_inventory' => 0.0,
            'dead_stock' => 0.0,
            'expected_pos_days' => 0.0,
            'actual_pos_days' => 0.0,
        ];

        $issueBuckets = [
            'weak_sales_growth' => [],
            'low_net_margin' => [],
            'high_expense_ratio' => [],
            'high_dead_stock' => [],
            'low_pos_compliance' => [],
        ];

        $under80 = [];
        $anomalyBranchCount = 0;
        $highRiskBranchCount = 0;
        $totalEstimatedImpactPhp = 0.0;
        $severityScoreTotal = 0.0;
        $confidenceScoreTotal = 0.0;

        foreach ($analysis as $item) {
            $scoreTotal += (float)$item['overall_score'];
            $status = (string)$item['status'];
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }

            if ($item['overall_score'] < 80) {
                $under80[] = $item;
            }

            $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                ? $item['decision_signals']
                : [];
            $anomalyFlags = (isset($signals['anomaly_flags']) && is_array($signals['anomaly_flags']))
                ? $signals['anomaly_flags']
                : [];
            if (!empty($anomalyFlags)) {
                $anomalyBranchCount++;
            }
            if (
                isset($signals['risk_30d']['level']) &&
                is_string($signals['risk_30d']['level']) &&
                strtoupper($signals['risk_30d']['level']) === 'HIGH'
            ) {
                $highRiskBranchCount++;
            }
            $totalEstimatedImpactPhp += (float)($signals['estimated_impact_php'] ?? 0.0);
            $severityScoreTotal += (float)($signals['severity_score'] ?? 0.0);
            $confidenceScoreTotal += (float)($signals['confidence_score'] ?? 0.0);

            $metrics = $item['metrics'];

            $totals['current_sales'] += $metrics['current_sales'];
            $totals['previous_sales'] += $metrics['previous_sales'];
            $totals['expenses'] += $metrics['expenses'];
            $totals['cogs'] += $metrics['cogs'];
            $totals['avg_inventory'] += $metrics['avg_inventory'];
            $totals['dead_stock'] += $metrics['dead_stock'];
            $totals['expected_pos_days'] += $metrics['expected_pos_days'];
            $totals['actual_pos_days'] += $metrics['actual_pos_days'];

            if ($metrics['sales_growth_rate'] !== null && $metrics['sales_growth_rate'] < self::SALES_GROWTH_TARGET) {
                $issueBuckets['weak_sales_growth'][] = $item;
            }
            if ($metrics['net_margin'] !== null && $metrics['net_margin'] < self::NET_MARGIN_TARGET) {
                $issueBuckets['low_net_margin'][] = $item;
            }
            if ($metrics['expense_ratio'] !== null && $metrics['expense_ratio'] > self::EXPENSE_RATIO_LIMIT) {
                $issueBuckets['high_expense_ratio'][] = $item;
            }
            if ($metrics['dead_stock_ratio'] !== null && $metrics['dead_stock_ratio'] > self::DEAD_STOCK_RATIO_LIMIT) {
                $issueBuckets['high_dead_stock'][] = $item;
            }
            if ($metrics['pos_compliance_rate'] !== null && $metrics['pos_compliance_rate'] < self::POS_COMPLIANCE_TARGET) {
                $issueBuckets['low_pos_compliance'][] = $item;
            }
        }

        $sorted = $analysis;
        usort($sorted, function ($a, $b) {
            $scoreCompare = ((int)($b['overall_score'] ?? 0)) <=> ((int)($a['overall_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcasecmp((string)($a['branch_name'] ?? ''), (string)($b['branch_name'] ?? ''));
        });

        $portfolioGrowth = $totals['previous_sales'] > 0
            ? ($totals['current_sales'] - $totals['previous_sales']) / $totals['previous_sales']
            : null;
        $portfolioNetIncome = $totals['current_sales'] - $totals['expenses'] - $totals['cogs'];
        $portfolioNetMargin = $totals['current_sales'] > 0
            ? $portfolioNetIncome / $totals['current_sales']
            : null;
        $portfolioExpenseRatio = $totals['current_sales'] > 0
            ? $totals['expenses'] / $totals['current_sales']
            : null;
        $portfolioDeadStockRatio = $totals['avg_inventory'] > 0
            ? $totals['dead_stock'] / $totals['avg_inventory']
            : null;
        $portfolioPosCompliance = $totals['expected_pos_days'] > 0
            ? $totals['actual_pos_days'] / $totals['expected_pos_days']
            : null;

        return [
            'branch_count' => $branchCount,
            'branches' => $analysis,
            'status_counts' => $statusCounts,
            'average_score' => round($scoreTotal / $branchCount, 1),
            'portfolio_growth' => $portfolioGrowth,
            'portfolio_net_margin' => $portfolioNetMargin,
            'portfolio_expense_ratio' => $portfolioExpenseRatio,
            'portfolio_dead_stock_ratio' => $portfolioDeadStockRatio,
            'portfolio_pos_compliance' => $portfolioPosCompliance,
            'issue_buckets' => $issueBuckets,
            'top_branch' => $sorted[0],
            'bottom_branch' => $sorted[$branchCount - 1],
            'under_80' => $under80,
            'decision_overview' => [
                'anomaly_branch_count' => $anomalyBranchCount,
                'high_risk_branch_count' => $highRiskBranchCount,
                'total_estimated_impact_php' => $totalEstimatedImpactPhp,
                'average_severity_score' => round($severityScoreTotal / max(1, $branchCount), 1),
                'average_confidence_score' => round($confidenceScoreTotal / max(1, $branchCount), 2),
            ],
        ];
    }

    private function buildOverviewSummary(array $portfolio): string
    {
        if ($portfolio['branch_count'] === 0) {
            return 'No branch data available.';
        }

        $status = $portfolio['status_counts'];

        $line1 = sprintf(
            'Average health score is %s across %d branches: %d excellent, %d good, %d warning, %d critical.',
            number_format((float)$portfolio['average_score'], 1),
            $portfolio['branch_count'],
            $status['EXCELLENT'],
            $status['GOOD'],
            $status['WARNING'],
            $status['CRITICAL']
        );

        $top = $portfolio['top_branch'];
        $bottom = $portfolio['bottom_branch'];
        $line2 = sprintf(
            'Best-performing branch is %s (score: %d), while %s (score: %d) needs the closest attention.',
            $top['branch_name'],
            $top['overall_score'],
            $bottom['branch_name'],
            $bottom['overall_score']
        );

        $riskSnippets = [];
        $buckets = $portfolio['issue_buckets'];

        if (count($buckets['weak_sales_growth']) > 0) {
            $riskSnippets[] = count($buckets['weak_sales_growth']) . ' branches with slow sales growth';
        }
        if (count($buckets['high_expense_ratio']) > 0) {
            $riskSnippets[] = count($buckets['high_expense_ratio']) . ' branches with high day-to-day costs';
        }
        if (count($buckets['high_dead_stock']) > 0) {
            $riskSnippets[] = count($buckets['high_dead_stock']) . ' branches with too much slow-moving stock';
        }
        if (count($buckets['low_pos_compliance']) > 0) {
            $riskSnippets[] = count($buckets['low_pos_compliance']) . ' branches with missed POS updates';
        }
        if (count($buckets['low_net_margin']) > 0) {
            $riskSnippets[] = count($buckets['low_net_margin']) . ' branches with low take-home profit';
        }

        if (!$riskSnippets) {
            $line3 = 'No major warning signs are showing in current branch operations.';
        } else {
            $line3 = 'Current risk flags: ' . implode(', ', $riskSnippets) . '.';
        }

        $line4 = sprintf(
            'Business snapshot: sales changed by %s, take-home profit is %s of sales, operating costs are %s of sales, slow-moving stock is %s of inventory, and POS updates are %s of expected days.',
            $this->formatPercent($portfolio['portfolio_growth']),
            $this->formatPercent($portfolio['portfolio_net_margin']),
            $this->formatPercent($portfolio['portfolio_expense_ratio']),
            $this->formatPercent($portfolio['portfolio_dead_stock_ratio']),
            $this->formatPercent($portfolio['portfolio_pos_compliance'])
        );

        return $line1 . ' ' . $line2 . ' ' . $line3 . ' ' . $line4;
    }

    private function buildOverviewRecommendations(array $portfolio): array
    {
        $recommendations = [];
        $buckets = $portfolio['issue_buckets'];
        $allBranches = (isset($portfolio['branches']) && is_array($portfolio['branches']))
            ? $portfolio['branches']
            : [];

        $sortedByDecisionRisk = $allBranches;
        usort($sortedByDecisionRisk, function ($a, $b) {
            $aSignals = (isset($a['decision_signals']) && is_array($a['decision_signals'])) ? $a['decision_signals'] : [];
            $bSignals = (isset($b['decision_signals']) && is_array($b['decision_signals'])) ? $b['decision_signals'] : [];

            $aSeverity = (float)($aSignals['severity_score'] ?? 0.0);
            $bSeverity = (float)($bSignals['severity_score'] ?? 0.0);
            if ($bSeverity !== $aSeverity) {
                return $bSeverity <=> $aSeverity;
            }

            $aImpact = (float)($aSignals['estimated_impact_php'] ?? 0.0);
            $bImpact = (float)($bSignals['estimated_impact_php'] ?? 0.0);
            return $bImpact <=> $aImpact;
        });

        $anomalyFocus = array_values(array_filter($sortedByDecisionRisk, function ($item) {
            $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                ? $item['decision_signals']
                : [];
            $flags = (isset($signals['anomaly_flags']) && is_array($signals['anomaly_flags']))
                ? $signals['anomaly_flags']
                : [];
            return !empty($flags);
        }));

        if (!empty($anomalyFocus)) {
            $focus = array_slice($anomalyFocus, 0, 3);
            $flagCount = 0;
            foreach ($focus as $item) {
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $flags = (isset($signals['anomaly_flags']) && is_array($signals['anomaly_flags']))
                    ? $signals['anomaly_flags']
                    : [];
                $flagCount += count($flags);
            }

            $recommendations[] = [
                'priority' => 'HIGH',
                'action' => 'Run immediate data validation and approval hold on non-essential spend in ' . $this->joinBranchNames($focus) . '.',
                'reason' => 'Detected ' . $flagCount . ' anomaly signals; expense ratios include ' . $this->formatMetricList($focus, 'expense_ratio') . ', which can distort decisions if unverified.',
                '_rank' => 400 + $flagCount,
            ];
        }

        if (count($buckets['high_expense_ratio']) > 0) {
            $focus = $this->topBranchesForIssue($buckets['high_expense_ratio'], function ($item) {
                $metricGap = $item['metrics']['expense_ratio'] - self::EXPENSE_RATIO_LIMIT;
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $severityBoost = ((float)($signals['severity_score'] ?? 0.0)) / 100.0;
                return $metricGap + $severityBoost;
            });
            $impact = 0.0;
            $highRiskCount = 0;
            foreach ($focus as $item) {
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $impact += (float)($signals['estimated_impact_php'] ?? 0.0);
                $risk30d = strtoupper((string)($signals['risk_30d']['level'] ?? 'LOW'));
                if ($risk30d === 'HIGH') {
                    $highRiskCount++;
                }
            }
            $recommendations[] = [
                'priority' => 'HIGH',
                'action' => 'Cut avoidable expenses this month in ' . $this->joinBranchNames($focus) . '.',
                'reason' => 'Expenses are using ' . $this->formatMetricList($focus, 'expense_ratio') . ' of sales with estimated impact around ' . $this->formatMoney($impact) . '; ' . $highRiskCount . ' of these branches are projected HIGH risk in 30 days.',
                '_rank' => 320 + (int)round($impact / 50000),
            ];
        }

        if (count($buckets['high_dead_stock']) > 0) {
            $focus = $this->topBranchesForIssue($buckets['high_dead_stock'], function ($item) {
                $metricGap = $item['metrics']['dead_stock_ratio'] - self::DEAD_STOCK_RATIO_LIMIT;
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $impactBoost = ((float)($signals['estimated_impact_php'] ?? 0.0)) / 1000000.0;
                return $metricGap + $impactBoost;
            });
            $capitalPressure = 0.0;
            foreach ($focus as $item) {
                $capitalPressure += max(0.0, ((float)($item['metrics']['dead_stock'] ?? 0.0)) * 0.20);
            }
            $recommendations[] = [
                'priority' => 'HIGH',
                'action' => 'Clear slow-moving items and tighten re-ordering in ' . $this->joinBranchNames($focus) . '.',
                'reason' => 'Slow-moving stock levels are ' . $this->formatMetricList($focus, 'dead_stock_ratio') . ', tying up about ' . $this->formatMoney($capitalPressure) . ' in recoverable working capital.',
                '_rank' => 260 + (int)round($capitalPressure / 50000),
            ];
        }

        if (count($buckets['low_pos_compliance']) > 0) {
            $focus = $this->topBranchesForIssue($buckets['low_pos_compliance'], function ($item) {
                $metricGap = self::POS_COMPLIANCE_TARGET - $item['metrics']['pos_compliance_rate'];
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $confidencePenalty = 1.0 - (float)($signals['confidence_score'] ?? 0.9);
                return $metricGap + $confidencePenalty;
            });
            $recommendations[] = [
                'priority' => 'MEDIUM',
                'action' => 'Make sure daily POS entries are completed every day in ' . $this->joinBranchNames($focus) . '.',
                'reason' => 'POS completion rates are ' . $this->formatMetricList($focus, 'pos_compliance_rate') . ', and missed logs reduce confidence in cost and profit signals.',
                '_rank' => 180,
            ];
        }

        if (count($buckets['weak_sales_growth']) > 0) {
            $focus = $this->topBranchesForIssue($buckets['weak_sales_growth'], function ($item) {
                $metricGap = self::SALES_GROWTH_TARGET - $item['metrics']['sales_growth_rate'];
                $risk30d = strtoupper((string)($item['decision_signals']['risk_30d']['level'] ?? 'LOW'));
                $riskBoost = $risk30d === 'HIGH' ? 0.10 : ($risk30d === 'MEDIUM' ? 0.05 : 0.0);
                return $metricGap + $riskBoost;
            });
            $projectedLossRiskCount = 0;
            foreach ($focus as $item) {
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                if (strtoupper((string)($signals['risk_60d']['level'] ?? 'LOW')) === 'HIGH') {
                    $projectedLossRiskCount++;
                }
            }
            $recommendations[] = [
                'priority' => 'MEDIUM',
                'action' => 'Run simple local promos and follow-up programs in ' . $this->joinBranchNames($focus) . ' to lift sales.',
                'reason' => 'Sales growth is ' . $this->formatMetricList($focus, 'sales_growth_rate') . ' versus a ' . $this->formatPercent(self::SALES_GROWTH_TARGET) . ' target; ' . $projectedLossRiskCount . ' branches are projected HIGH risk within 60 days if trend persists.',
                '_rank' => 150 + ($projectedLossRiskCount * 5),
            ];
        }

        if (count($buckets['low_net_margin']) > 0) {
            $focus = $this->topBranchesForIssue($buckets['low_net_margin'], function ($item) {
                $metricGap = self::NET_MARGIN_TARGET - $item['metrics']['net_margin'];
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $impactBoost = ((float)($signals['estimated_impact_php'] ?? 0.0)) / 1000000.0;
                return $metricGap + $impactBoost;
            });
            $impact = 0.0;
            foreach ($focus as $item) {
                $signals = (isset($item['decision_signals']) && is_array($item['decision_signals']))
                    ? $item['decision_signals']
                    : [];
                $impact += (float)($signals['estimated_impact_php'] ?? 0.0);
            }
            $recommendations[] = [
                'priority' => 'MEDIUM',
                'action' => 'Review pricing, discounts, and wastage controls in ' . $this->joinBranchNames($focus) . ' to improve take-home profit.',
                'reason' => 'Take-home profit rates are ' . $this->formatMetricList($focus, 'net_margin') . ' versus a ' . $this->formatPercent(self::NET_MARGIN_TARGET) . ' target, with estimated margin recovery potential near ' . $this->formatMoney($impact) . '.',
                '_rank' => 140 + (int)round($impact / 50000),
            ];
        }

        if (!$recommendations) {
            return [
                [
                    'priority' => 'LOW',
                    'action' => 'Maintain current operating controls and monitor weekly KPI variance.',
                    'reason' => 'Current branch results are stable across sales, costs, inventory movement, and daily operations, with no triggered threshold breaches.',
                ],
            ];
        }

        usort($recommendations, function ($a, $b) {
            $weight = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
            $prioritySort = $weight[$b['priority']] <=> $weight[$a['priority']];
            if ($prioritySort !== 0) {
                return $prioritySort;
            }
            return ((int)($b['_rank'] ?? 0)) <=> ((int)($a['_rank'] ?? 0));
        });

        $recommendations = array_slice($recommendations, 0, 3);
        foreach ($recommendations as &$item) {
            unset($item['_rank']);
        }
        unset($item);

        return $recommendations;
    }

    private function buildMonthlyNarrative(array $month, ?array $branchContext = null, ?array $portfolioContext = null): array
    {
        if (is_array($branchContext)) {
            return $this->buildBranchMonthlyNarrative($month, $branchContext);
        }

        $monthLabel = trim((string)($month['month_label'] ?? ''));
        $monthKey = trim((string)($month['month_key'] ?? ''));
        if ($monthLabel === '') {
            $monthLabel = $monthKey !== '' ? $monthKey : 'Selected month';
        }

        $branchCount = isset($month['branch_count']) ? (int)$month['branch_count'] : 0;
        $averageScore = isset($month['average_score']) ? (int)$month['average_score'] : 0;
        $riskCount = isset($month['risk_count']) ? (int)$month['risk_count'] : 0;
        $statusCounts = $this->buildMonthlyStatusCounts($month);

        $topName = trim((string)($month['top_branch']['branch_name'] ?? 'N/A'));
        $topScore = isset($month['top_branch']['overall_score']) ? (int)$month['top_branch']['overall_score'] : 0;
        $bottomName = trim((string)($month['bottom_branch']['branch_name'] ?? 'N/A'));
        $bottomScore = isset($month['bottom_branch']['overall_score']) ? (int)$month['bottom_branch']['overall_score'] : 0;
        $scoreSpread = max(0, $topScore - $bottomScore);
        $singleBranchScope = $branchCount === 1 && $topName !== '' && $topName !== 'N/A';

        $lines = [];
        if ($singleBranchScope) {
            $lines[] = sprintf(
                '%s snapshot: %s scored %d/100 with status %s.',
                $monthLabel,
                $topName,
                $topScore,
                (string)($month['top_branch']['status'] ?? 'N/A')
            );
            $lines[] = sprintf(
                'This branch monthly view covers 1 branch only, with current risk count at %d.',
                $riskCount
            );
        } else {
            $lines[] = sprintf(
                '%s snapshot: average health score is %d across %d branches (%d excellent, %d good, %d warning, %d critical).',
                $monthLabel,
                $averageScore,
                $branchCount,
                $statusCounts['EXCELLENT'],
                $statusCounts['GOOD'],
                $statusCounts['WARNING'],
                $statusCounts['CRITICAL']
            );

            $lines[] = sprintf(
                'Top performer is %s at %d/100, while %s is lowest at %d/100 (spread: %d points).',
                $topName,
                $topScore,
                $bottomName,
                $bottomScore,
                $scoreSpread
            );
        }

        if ($riskCount > 0) {
            $riskNames = $this->pickBranchNamesByStatus($month, ['warning', 'critical'], 3);
            $lines[] = sprintf(
                '%d branches are currently at risk (warning or critical), led by %s.',
                $riskCount,
                $riskNames !== '' ? $riskNames : 'the lowest-scoring branches'
            );
        } else {
            $lines[] = 'No branch is in warning or critical status for this month.';
        }

        $forwardRiskLevel = $this->classifyPortfolioForwardRisk($statusCounts, $branchCount, $riskCount);
        $forwardRiskExposure = $statusCounts['CRITICAL'] + $statusCounts['WARNING'];
        $lines[] = sprintf(
            'Forward risk (next 30-60 days): %s if current patterns hold, with up to %d branches likely to remain in warning/critical bands.',
            $forwardRiskLevel,
            $forwardRiskExposure
        );

        if (is_array($portfolioContext)) {
            $current = (isset($portfolioContext['current']) && is_array($portfolioContext['current']))
                ? $portfolioContext['current']
                : [];
            $previous = (isset($portfolioContext['previous']) && is_array($portfolioContext['previous']))
                ? $portfolioContext['previous']
                : null;
            $criticalDrivers = (isset($portfolioContext['critical_branch_drivers']) && is_array($portfolioContext['critical_branch_drivers']))
                ? $portfolioContext['critical_branch_drivers']
                : [];

            if ($previous) {
                $lines[] = sprintf(
                    'MoM movement vs %s: average score %+0.1f points, sales growth %+0.1fpp, net margin %+0.1fpp, expense ratio %+0.1fpp, dead stock ratio %+0.1fpp, and POS compliance %+0.1fpp.',
                    (string)($previous['month_label'] ?? ($previous['month_key'] ?? 'previous month')),
                    (float)($portfolioContext['delta_vs_previous']['average_score_points'] ?? 0.0),
                    (float)($portfolioContext['delta_vs_previous']['portfolio_growth_pp'] ?? 0.0),
                    (float)($portfolioContext['delta_vs_previous']['net_margin_pp'] ?? 0.0),
                    (float)($portfolioContext['delta_vs_previous']['expense_ratio_pp'] ?? 0.0),
                    (float)($portfolioContext['delta_vs_previous']['dead_stock_ratio_pp'] ?? 0.0),
                    (float)($portfolioContext['delta_vs_previous']['pos_compliance_pp'] ?? 0.0)
                );
            }

            if (!empty($criticalDrivers)) {
                $lines[] = 'Critical driver summary: ' . $this->joinLabels($criticalDrivers) . '.';
            }

            $lines[] = sprintf(
                'Estimated business impact this month is around %s from current critical inefficiencies and risk exposure.',
                (string)($current['total_estimated_impact_php'] ?? $this->formatMoney(0))
            );
        }

        if ($statusCounts['CRITICAL'] > 0) {
            $focus = $this->pickBranchNamesByStatus($month, ['critical'], 3);
            $lines[] = 'Main action: deploy immediate corrective plans for ' .
                ($focus !== '' ? $focus : 'critical branches') .
                ' and move them above the 60 score threshold.';
        } elseif ($statusCounts['WARNING'] > 0) {
            $focus = $this->pickBranchNamesByStatus($month, ['warning'], 3);
            $lines[] = 'Main action: run targeted operating reviews for ' .
                ($focus !== '' ? $focus : 'warning branches') .
                ' to push them into the good band.';
        } else {
            $lines[] = 'Main action: maintain current controls and monitor weekly score drift to keep all branches in good standing.';
        }

        return array_slice($lines, 0, 10);
    }

    private function buildBranchMonthlyNarrative(array $month, array $branchContext): array
    {
        $monthLabel = trim((string)($month['month_label'] ?? ''));
        if ($monthLabel === '') {
            $monthLabel = (string)($month['month_key'] ?? 'Selected month');
        }

        $branchName = trim((string)($branchContext['branch_name'] ?? 'Selected branch'));
        $current = (isset($branchContext['current']) && is_array($branchContext['current']))
            ? $branchContext['current']
            : [];
        $previous = (isset($branchContext['previous']) && is_array($branchContext['previous']))
            ? $branchContext['previous']
            : null;
        $deltas = (isset($branchContext['delta_vs_previous']) && is_array($branchContext['delta_vs_previous']))
            ? $branchContext['delta_vs_previous']
            : [];

        $lines = [];
        $lines[] = sprintf(
            '%s snapshot for %s: score is %d/100 with %s status (%s).',
            $monthLabel,
            $branchName,
            (int)($current['overall_score'] ?? 0),
            (string)($current['status'] ?? 'N/A'),
            (string)($current['status_text'] ?? 'N/A')
        );

        $lines[] = sprintf(
            'Sales moved from %s to %s (%s), and net income is %s.',
            (string)($current['previous_sales'] ?? 'n/a'),
            (string)($current['current_sales'] ?? 'n/a'),
            (string)($current['sales_growth_rate'] ?? 'n/a'),
            (string)($current['net_income'] ?? 'n/a')
        );

        $lines[] = sprintf(
            'Profitability and cost mix: net margin %s, expense ratio %s, dead stock ratio %s.',
            (string)($current['net_margin'] ?? 'n/a'),
            (string)($current['expense_ratio'] ?? 'n/a'),
            (string)($current['dead_stock_ratio'] ?? 'n/a')
        );

        $lines[] = sprintf(
            'Execution discipline: POS completion is %s (%d of %d days).',
            (string)($current['pos_compliance_rate'] ?? 'n/a'),
            (int)($current['actual_pos_days'] ?? 0),
            (int)($current['expected_pos_days'] ?? 0)
        );

        if ($previous) {
            $lines[] = sprintf(
                'Compared with %s, score changed by %+d points, net margin by %+0.1fpp, expense ratio by %+0.1fpp, dead stock ratio by %+0.1fpp, and POS compliance by %+0.1fpp.',
                (string)($previous['month_label'] ?? ($previous['month_key'] ?? 'previous month')),
                (int)($deltas['score_points'] ?? 0),
                (float)($deltas['net_margin_pp'] ?? 0.0),
                (float)($deltas['expense_ratio_pp'] ?? 0.0),
                (float)($deltas['dead_stock_ratio_pp'] ?? 0.0),
                (float)($deltas['pos_compliance_pp'] ?? 0.0)
            );
        }

        $lines[] = sprintf(
            'Forward risk (if unchanged): 30-day %s and 60-day %s, with projected net margin at %s then %s and projected net income at %s then %s.',
            (string)($current['risk_30d_level'] ?? 'LOW'),
            (string)($current['risk_60d_level'] ?? 'LOW'),
            (string)($current['projected_net_margin_30d'] ?? 'n/a'),
            (string)($current['projected_net_margin_60d'] ?? 'n/a'),
            (string)($current['projected_net_income_30d'] ?? 'n/a'),
            (string)($current['projected_net_income_60d'] ?? 'n/a')
        );

        foreach ($this->buildPriorityActionsForBranchContext($current) as $action) {
            $lines[] = $action;
        }

        return array_slice($lines, 0, 8);
    }

    private function buildYearlyNarrative(array $year, ?array $previousYear = null, bool $isBranchScope = false): array
    {
        $yearLabel = trim((string)($year['year_label'] ?? ''));
        $yearKey = trim((string)($year['year_key'] ?? ''));
        if ($yearLabel === '') {
            $yearLabel = $yearKey !== '' ? $yearKey : 'Selected year';
        }

        $branches = (isset($year['branches']) && is_array($year['branches'])) ? $year['branches'] : [];
        $branchCount = isset($year['branch_count']) ? (int)$year['branch_count'] : count($branches);
        $averageScore = isset($year['average_score']) ? (int)$year['average_score'] : 0;
        $riskCount = isset($year['risk_count']) ? (int)$year['risk_count'] : 0;
        $statusCounts = $this->buildMonthlyStatusCounts($year);
        $topName = trim((string)($year['top_branch']['branch_name'] ?? 'N/A'));
        $topScore = isset($year['top_branch']['overall_score']) ? (int)$year['top_branch']['overall_score'] : 0;
        $bottomName = trim((string)($year['bottom_branch']['branch_name'] ?? 'N/A'));
        $bottomScore = isset($year['bottom_branch']['overall_score']) ? (int)$year['bottom_branch']['overall_score'] : 0;

        $lines = [];
        if ($isBranchScope || $branchCount === 1) {
            $selectedBranch = null;
            if (!empty($branches) && is_array($branches[0])) {
                $selectedBranch = $branches[0];
            } elseif (isset($year['top_branch']) && is_array($year['top_branch'])) {
                $selectedBranch = $year['top_branch'];
            }
            $selectedName = trim((string)($selectedBranch['branch_name'] ?? $topName));
            $selectedScore = isset($selectedBranch['overall_score']) ? (int)$selectedBranch['overall_score'] : $topScore;
            $selectedStatus = (string)($selectedBranch['status'] ?? ($year['top_branch']['status'] ?? 'N/A'));
            $selectedStatusText = (string)($selectedBranch['status_text'] ?? ($year['top_branch']['status_text'] ?? 'N/A'));
            $sampleCount = isset($selectedBranch['sample_count']) ? (int)$selectedBranch['sample_count'] : 0;

            $lines[] = sprintf(
                '%s snapshot for %s: yearly average score is %d/100 with %s status (%s).',
                $yearLabel,
                $selectedName !== '' ? $selectedName : 'Selected branch',
                $selectedScore,
                $selectedStatus,
                $selectedStatusText
            );
            $lines[] = sprintf(
                'This yearly view summarizes %d month%s of reports and currently has %d risk flag(s).',
                $sampleCount,
                $sampleCount === 1 ? '' : 's',
                $riskCount
            );

            if (is_array($previousYear)) {
                $previousBranch = $selectedName !== '' ? $this->findYearBranchByName($previousYear, $selectedName) : null;
                if (is_array($previousBranch)) {
                    $previousLabel = (string)($previousYear['year_label'] ?? ($previousYear['year_key'] ?? 'previous year'));
                    $previousScore = isset($previousBranch['overall_score']) ? (int)$previousBranch['overall_score'] : 0;
                    $previousStatus = (string)($previousBranch['status'] ?? 'N/A');
                    $lines[] = sprintf(
                        'Year-over-year vs %s: score changed by %+d points, from %s to %s status.',
                        $previousLabel,
                        $selectedScore - $previousScore,
                        $previousStatus,
                        $selectedStatus
                    );
                }
            }
        } else {
            $lines[] = sprintf(
                '%s summary: average health score is %d across %d branches (%d excellent, %d good, %d warning, %d critical).',
                $yearLabel,
                $averageScore,
                $branchCount,
                $statusCounts['EXCELLENT'],
                $statusCounts['GOOD'],
                $statusCounts['WARNING'],
                $statusCounts['CRITICAL']
            );
            $lines[] = sprintf(
                'Top performer is %s at %d/100, while %s is lowest at %d/100.',
                $topName,
                $topScore,
                $bottomName,
                $bottomScore
            );

            if (is_array($previousYear)) {
                $previousLabel = (string)($previousYear['year_label'] ?? ($previousYear['year_key'] ?? 'previous year'));
                $previousAverage = isset($previousYear['average_score']) ? (int)$previousYear['average_score'] : 0;
                $previousRisk = isset($previousYear['risk_count']) ? (int)$previousYear['risk_count'] : 0;
                $previousBranchCount = isset($previousYear['branch_count']) ? (int)$previousYear['branch_count'] : 0;
                $lines[] = sprintf(
                    'Year-over-year vs %s: average score %+d points, at-risk branches %+d, and covered branches %+d.',
                    $previousLabel,
                    $averageScore - $previousAverage,
                    $riskCount - $previousRisk,
                    $branchCount - $previousBranchCount
                );
            }
        }

        if ($riskCount > 0) {
            $riskNames = $this->pickBranchNamesByStatus($year, ['warning', 'critical'], 3);
            $lines[] = sprintf(
                '%d branches are at risk for this year, led by %s.',
                $riskCount,
                $riskNames !== '' ? $riskNames : 'the lowest-scoring branches'
            );
        } else {
            $lines[] = 'No branch is in warning or critical status for this year.';
        }

        $forwardRiskLevel = $this->classifyPortfolioForwardRisk($statusCounts, $branchCount, $riskCount);
        $lines[] = sprintf(
            'Forward risk outlook for the next 12 months is %s if current patterns persist.',
            $forwardRiskLevel
        );

        if ($statusCounts['CRITICAL'] > 0) {
            $focus = $this->pickBranchNamesByStatus($year, ['critical'], 3);
            $lines[] = 'Main action: execute urgent recovery plans for ' .
                ($focus !== '' ? $focus : 'critical branches') .
                ' and raise each above 60/100.';
        } elseif ($statusCounts['WARNING'] > 0) {
            $focus = $this->pickBranchNamesByStatus($year, ['warning'], 3);
            $lines[] = 'Main action: run focused coaching for ' .
                ($focus !== '' ? $focus : 'warning branches') .
                ' to move them into the good band.';
        } else {
            $lines[] = 'Main action: preserve current controls and keep quarterly reviews active to prevent score drift.';
        }

        return array_slice($lines, 0, 9);
    }

    private function findYearBranchByName(array $year, string $branchName): ?array
    {
        $needle = trim($branchName);
        if ($needle === '') {
            return null;
        }

        $branches = (isset($year['branches']) && is_array($year['branches'])) ? $year['branches'] : [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $candidate = trim((string)($branch['branch_name'] ?? ''));
            if ($candidate !== '' && strcasecmp($candidate, $needle) === 0) {
                return $branch;
            }
        }

        return null;
    }

    private function buildPriorityActionsForBranchContext(array $current): array
    {
        $actions = [];

        $expenseRatio = $this->parsePercentValue($current['expense_ratio'] ?? null);
        $deadStockRatio = $this->parsePercentValue($current['dead_stock_ratio'] ?? null);
        $posCompliance = $this->parsePercentValue($current['pos_compliance_rate'] ?? null);
        $salesGrowth = $this->parsePercentValue($current['sales_growth_rate'] ?? null);
        $netMargin = $this->parsePercentValue($current['net_margin'] ?? null);

        if ($expenseRatio !== null && $expenseRatio > self::EXPENSE_RATIO_LIMIT) {
            $actions[] = 'Main action: reduce avoidable operating expenses and tighten spending approvals.';
        }
        if ($deadStockRatio !== null && $deadStockRatio > self::DEAD_STOCK_RATIO_LIMIT) {
            $actions[] = 'Main action: clear slow-moving inventory and rebalance replenishment to demand.';
        }
        if ($posCompliance !== null && $posCompliance < self::POS_COMPLIANCE_TARGET) {
            $actions[] = 'Main action: enforce daily POS completion discipline with end-of-day checks.';
        }
        if ($salesGrowth !== null && $salesGrowth < self::SALES_GROWTH_TARGET) {
            $actions[] = 'Main action: run focused local sales programs to recover growth momentum.';
        }
        if ($netMargin !== null && $netMargin < self::NET_MARGIN_TARGET) {
            $actions[] = 'Main action: review pricing and direct-cost leakage to improve take-home profit.';
        }

        if (!$actions) {
            $actions[] = 'Main action: sustain current execution quality and track weekly KPI drift.';
        }

        return $actions;
    }

    private function parsePercentValue($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '' || strtolower($raw) === 'n/a') {
            return null;
        }

        if (substr($raw, -1) === '%') {
            $raw = substr($raw, 0, -1);
            if (!is_numeric($raw)) {
                return null;
            }
            return ((float)$raw) / 100;
        }

        return is_numeric($raw) ? (float)$raw : null;
    }

    private function buildSelectedBranchMonthlyContext(string $branchName, string $monthKey): ?array
    {
        $branchName = trim($branchName);
        $monthKey = trim($monthKey);
        if ($branchName === '' || $monthKey === '') {
            return null;
        }

        $reports = $this->branchRepository->getMonthlyReports($branchName, 24);
        if (!is_array($reports) || !$reports) {
            return null;
        }

        $currentReport = null;
        $currentBranchName = '';
        $previousReport = null;
        $previousMonthKey = '';

        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $candidateBranch = trim((string)($report['branch'] ?? ''));
            if ($candidateBranch === '' || strcasecmp($candidateBranch, $branchName) !== 0) {
                continue;
            }

            $candidateMonthKey = $this->normalizeMonthKey((string)($report['reporting_period'] ?? ''));
            if ($candidateMonthKey === null) {
                continue;
            }

            if ($currentBranchName === '') {
                $currentBranchName = $candidateBranch;
            }

            if ($candidateMonthKey === $monthKey) {
                $currentReport = $report;
                continue;
            }

            if (strcmp($candidateMonthKey, $monthKey) < 0 && ($previousMonthKey === '' || strcmp($candidateMonthKey, $previousMonthKey) > 0)) {
                $previousMonthKey = $candidateMonthKey;
                $previousReport = $report;
            }
        }

        if (!is_array($currentReport)) {
            return null;
        }

        $currentAnalysis = $this->analyzeBranch($currentReport);
        $previousAnalysis = is_array($previousReport) ? $this->analyzeBranch($previousReport) : null;

        return $this->buildBranchMonthlyContextPayload(
            $currentBranchName !== '' ? $currentBranchName : $branchName,
            $monthKey,
            $currentAnalysis,
            $previousAnalysis,
            $previousMonthKey
        );
    }

    private function buildBranchMonthlyContextPayload(
        string $branchName,
        string $monthKey,
        array $currentAnalysis,
        ?array $previousAnalysis,
        string $previousMonthKey
    ): array {
        $currentMetrics = $currentAnalysis['metrics'] ?? [];
        $previousMetrics = $previousAnalysis['metrics'] ?? [];
        $currentSignals = (isset($currentAnalysis['decision_signals']) && is_array($currentAnalysis['decision_signals']))
            ? $currentAnalysis['decision_signals']
            : [];

        $currentPayload = [
            'overall_score' => (int)($currentAnalysis['overall_score'] ?? 0),
            'status' => (string)($currentAnalysis['status'] ?? ''),
            'status_text' => (string)($currentAnalysis['status_text'] ?? ''),
            'current_sales' => $this->formatMoney($currentMetrics['current_sales'] ?? 0),
            'previous_sales' => $this->formatMoney($currentMetrics['previous_sales'] ?? 0),
            'net_income' => $this->formatMoney($currentMetrics['net_income'] ?? 0),
            'sales_growth_rate' => $this->formatPercent($currentMetrics['sales_growth_rate'] ?? null),
            'net_margin' => $this->formatPercent($currentMetrics['net_margin'] ?? null),
            'expense_ratio' => $this->formatPercent($currentMetrics['expense_ratio'] ?? null),
            'dead_stock_ratio' => $this->formatPercent($currentMetrics['dead_stock_ratio'] ?? null),
            'pos_compliance_rate' => $this->formatPercent($currentMetrics['pos_compliance_rate'] ?? null),
            'actual_pos_days' => (int)($currentMetrics['actual_pos_days'] ?? 0),
            'expected_pos_days' => (int)($currentMetrics['expected_pos_days'] ?? 0),
            'severity_score' => (int)($currentSignals['severity_score'] ?? 0),
            'confidence_score' => (float)($currentSignals['confidence_score'] ?? 0.0),
            'estimated_impact_php' => $this->formatMoney((float)($currentSignals['estimated_impact_php'] ?? 0.0)),
            'anomaly_flag_count' => isset($currentSignals['anomaly_flags']) && is_array($currentSignals['anomaly_flags'])
                ? count($currentSignals['anomaly_flags'])
                : 0,
            'risk_30d_level' => (string)($currentSignals['risk_30d']['level'] ?? 'LOW'),
            'risk_60d_level' => (string)($currentSignals['risk_60d']['level'] ?? 'LOW'),
            'projected_net_income_30d' => $this->formatMoney((float)($currentSignals['risk_30d']['projected_net_income'] ?? 0.0)),
            'projected_net_margin_30d' => $this->formatPercent($currentSignals['risk_30d']['projected_net_margin'] ?? null),
            'projected_net_income_60d' => $this->formatMoney((float)($currentSignals['risk_60d']['projected_net_income'] ?? 0.0)),
            'projected_net_margin_60d' => $this->formatPercent($currentSignals['risk_60d']['projected_net_margin'] ?? null),
            'factor_scores' => array_map(function ($factor) {
                return [
                    'name' => (string)($factor['name'] ?? ''),
                    'score' => isset($factor['score']) ? (int)$factor['score'] : 0,
                    'weight' => isset($factor['weight']) ? (int)$factor['weight'] : 0,
                ];
            }, $currentAnalysis['factors'] ?? []),
        ];

        $previousPayload = null;
        $deltaPayload = null;
        if (is_array($previousAnalysis)) {
            $previousPayload = [
                'month_key' => $previousMonthKey,
                'month_label' => $this->formatMonthLabel($previousMonthKey),
                'overall_score' => (int)($previousAnalysis['overall_score'] ?? 0),
                'status' => (string)($previousAnalysis['status'] ?? ''),
                'status_text' => (string)($previousAnalysis['status_text'] ?? ''),
                'current_sales' => $this->formatMoney($previousMetrics['current_sales'] ?? 0),
                'previous_sales' => $this->formatMoney($previousMetrics['previous_sales'] ?? 0),
                'net_income' => $this->formatMoney($previousMetrics['net_income'] ?? 0),
                'sales_growth_rate' => $this->formatPercent($previousMetrics['sales_growth_rate'] ?? null),
                'net_margin' => $this->formatPercent($previousMetrics['net_margin'] ?? null),
                'expense_ratio' => $this->formatPercent($previousMetrics['expense_ratio'] ?? null),
                'dead_stock_ratio' => $this->formatPercent($previousMetrics['dead_stock_ratio'] ?? null),
                'pos_compliance_rate' => $this->formatPercent($previousMetrics['pos_compliance_rate'] ?? null),
                'actual_pos_days' => (int)($previousMetrics['actual_pos_days'] ?? 0),
                'expected_pos_days' => (int)($previousMetrics['expected_pos_days'] ?? 0),
            ];

            $deltaPayload = [
                'score_points' => (int)($currentAnalysis['overall_score'] ?? 0) - (int)($previousAnalysis['overall_score'] ?? 0),
                'sales_growth_pp' => (($currentMetrics['sales_growth_rate'] ?? null) !== null && ($previousMetrics['sales_growth_rate'] ?? null) !== null)
                    ? ((float)$currentMetrics['sales_growth_rate'] - (float)$previousMetrics['sales_growth_rate']) * 100
                    : 0.0,
                'net_margin_pp' => (($currentMetrics['net_margin'] ?? null) !== null && ($previousMetrics['net_margin'] ?? null) !== null)
                    ? ((float)$currentMetrics['net_margin'] - (float)$previousMetrics['net_margin']) * 100
                    : 0.0,
                'expense_ratio_pp' => (($currentMetrics['expense_ratio'] ?? null) !== null && ($previousMetrics['expense_ratio'] ?? null) !== null)
                    ? ((float)$currentMetrics['expense_ratio'] - (float)$previousMetrics['expense_ratio']) * 100
                    : 0.0,
                'dead_stock_ratio_pp' => (($currentMetrics['dead_stock_ratio'] ?? null) !== null && ($previousMetrics['dead_stock_ratio'] ?? null) !== null)
                    ? ((float)$currentMetrics['dead_stock_ratio'] - (float)$previousMetrics['dead_stock_ratio']) * 100
                    : 0.0,
                'pos_compliance_pp' => (($currentMetrics['pos_compliance_rate'] ?? null) !== null && ($previousMetrics['pos_compliance_rate'] ?? null) !== null)
                    ? ((float)$currentMetrics['pos_compliance_rate'] - (float)$previousMetrics['pos_compliance_rate']) * 100
                    : 0.0,
            ];
        }

        return [
            'branch_name' => $branchName,
            'month_key' => $monthKey,
            'month_label' => $this->formatMonthLabel($monthKey),
            'current' => $currentPayload,
            'previous' => $previousPayload,
            'delta_vs_previous' => $deltaPayload,
        ];
    }

    private function buildPortfolioMonthlyContext(string $monthKey): ?array
    {
        $monthKey = trim($monthKey);
        if ($monthKey === '') {
            return null;
        }

        $reports = $this->branchRepository->getMonthlyReports(null, 24);
        if (!is_array($reports) || !$reports) {
            return null;
        }

        $currentRows = [];
        $previousRows = [];
        $previousMonthKey = '';

        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }
            $candidateMonthKey = $this->normalizeMonthKey((string)($report['reporting_period'] ?? ''));
            if ($candidateMonthKey === null) {
                continue;
            }

            if ($candidateMonthKey === $monthKey) {
                $currentRows[] = $report;
                continue;
            }

            if (strcmp($candidateMonthKey, $monthKey) < 0 && ($previousMonthKey === '' || strcmp($candidateMonthKey, $previousMonthKey) > 0)) {
                $previousMonthKey = $candidateMonthKey;
            }
        }

        if ($previousMonthKey !== '') {
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }
                $candidateMonthKey = $this->normalizeMonthKey((string)($report['reporting_period'] ?? ''));
                if ($candidateMonthKey === $previousMonthKey) {
                    $previousRows[] = $report;
                }
            }
        }

        if (!$currentRows) {
            return null;
        }

        $currentAnalysis = array_map(function ($row) {
            return $this->analyzeBranch($row);
        }, $currentRows);
        $currentPortfolio = $this->buildPortfolioAnalysis($currentAnalysis);

        $previousPortfolio = null;
        if ($previousRows) {
            $previousAnalysis = array_map(function ($row) {
                return $this->analyzeBranch($row);
            }, $previousRows);
            $previousPortfolio = $this->buildPortfolioAnalysis($previousAnalysis);
        }

        $criticalBranches = array_values(array_filter($currentAnalysis, function ($item) {
            return strtoupper((string)($item['status'] ?? '')) === 'CRITICAL';
        }));

        $currentPayload = [
            'month_key' => $monthKey,
            'month_label' => $this->formatMonthLabel($monthKey),
            'average_score' => round((float)($currentPortfolio['average_score'] ?? 0.0), 1),
            'portfolio_growth' => $this->formatPercent($currentPortfolio['portfolio_growth'] ?? null),
            'portfolio_net_margin' => $this->formatPercent($currentPortfolio['portfolio_net_margin'] ?? null),
            'portfolio_expense_ratio' => $this->formatPercent($currentPortfolio['portfolio_expense_ratio'] ?? null),
            'portfolio_dead_stock_ratio' => $this->formatPercent($currentPortfolio['portfolio_dead_stock_ratio'] ?? null),
            'portfolio_pos_compliance' => $this->formatPercent($currentPortfolio['portfolio_pos_compliance'] ?? null),
            'critical_count' => (int)(($currentPortfolio['status_counts']['CRITICAL'] ?? 0)),
            'warning_count' => (int)(($currentPortfolio['status_counts']['WARNING'] ?? 0)),
            'total_estimated_impact_php' => $this->formatMoney((float)($currentPortfolio['decision_overview']['total_estimated_impact_php'] ?? 0.0)),
            'high_risk_branch_count' => (int)($currentPortfolio['decision_overview']['high_risk_branch_count'] ?? 0),
            'forward_risk_level' => $this->classifyPortfolioForwardRisk(
                $currentPortfolio['status_counts'] ?? [],
                (int)($currentPortfolio['branch_count'] ?? 0),
                (int)(($currentPortfolio['status_counts']['CRITICAL'] ?? 0) + ($currentPortfolio['status_counts']['WARNING'] ?? 0))
            ),
        ];

        $previousPayload = null;
        $deltaPayload = null;
        if (is_array($previousPortfolio)) {
            $previousPayload = [
                'month_key' => $previousMonthKey,
                'month_label' => $this->formatMonthLabel($previousMonthKey),
                'average_score' => round((float)($previousPortfolio['average_score'] ?? 0.0), 1),
                'portfolio_growth' => $this->formatPercent($previousPortfolio['portfolio_growth'] ?? null),
                'portfolio_net_margin' => $this->formatPercent($previousPortfolio['portfolio_net_margin'] ?? null),
                'portfolio_expense_ratio' => $this->formatPercent($previousPortfolio['portfolio_expense_ratio'] ?? null),
                'portfolio_dead_stock_ratio' => $this->formatPercent($previousPortfolio['portfolio_dead_stock_ratio'] ?? null),
                'portfolio_pos_compliance' => $this->formatPercent($previousPortfolio['portfolio_pos_compliance'] ?? null),
            ];

            $deltaPayload = [
                'average_score_points' => round(
                    (float)($currentPortfolio['average_score'] ?? 0.0) - (float)($previousPortfolio['average_score'] ?? 0.0),
                    1
                ),
                'portfolio_growth_pp' => round(
                    ((float)($currentPortfolio['portfolio_growth'] ?? 0.0) - (float)($previousPortfolio['portfolio_growth'] ?? 0.0)) * 100,
                    1
                ),
                'net_margin_pp' => round(
                    ((float)($currentPortfolio['portfolio_net_margin'] ?? 0.0) - (float)($previousPortfolio['portfolio_net_margin'] ?? 0.0)) * 100,
                    1
                ),
                'expense_ratio_pp' => round(
                    ((float)($currentPortfolio['portfolio_expense_ratio'] ?? 0.0) - (float)($previousPortfolio['portfolio_expense_ratio'] ?? 0.0)) * 100,
                    1
                ),
                'dead_stock_ratio_pp' => round(
                    ((float)($currentPortfolio['portfolio_dead_stock_ratio'] ?? 0.0) - (float)($previousPortfolio['portfolio_dead_stock_ratio'] ?? 0.0)) * 100,
                    1
                ),
                'pos_compliance_pp' => round(
                    ((float)($currentPortfolio['portfolio_pos_compliance'] ?? 0.0) - (float)($previousPortfolio['portfolio_pos_compliance'] ?? 0.0)) * 100,
                    1
                ),
            ];
        }

        return [
            'current' => $currentPayload,
            'previous' => $previousPayload,
            'delta_vs_previous' => $deltaPayload,
            'critical_branch_drivers' => $this->computeCriticalBranchDriverSummary($criticalBranches),
        ];
    }

    private function computeCriticalBranchDriverSummary(array $criticalBranches): array
    {
        $drivers = [];

        foreach (array_slice($criticalBranches, 0, 4) as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $name = trim((string)($branch['branch_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $m = (isset($branch['metrics']) && is_array($branch['metrics'])) ? $branch['metrics'] : [];
            $parts = [];
            if (isset($m['sales_growth_rate']) && $m['sales_growth_rate'] !== null && (float)$m['sales_growth_rate'] < self::SALES_GROWTH_TARGET) {
                $parts[] = 'sales growth ' . $this->formatPercent($m['sales_growth_rate']);
            }
            if (isset($m['net_margin']) && $m['net_margin'] !== null && (float)$m['net_margin'] < self::NET_MARGIN_TARGET) {
                $parts[] = 'net margin ' . $this->formatPercent($m['net_margin']);
            }
            if (isset($m['expense_ratio']) && $m['expense_ratio'] !== null && (float)$m['expense_ratio'] > self::EXPENSE_RATIO_LIMIT) {
                $parts[] = 'expense ratio ' . $this->formatPercent($m['expense_ratio']);
            }
            if (isset($m['dead_stock_ratio']) && $m['dead_stock_ratio'] !== null && (float)$m['dead_stock_ratio'] > self::DEAD_STOCK_RATIO_LIMIT) {
                $parts[] = 'dead stock ' . $this->formatPercent($m['dead_stock_ratio']);
            }
            if (isset($m['pos_compliance_rate']) && $m['pos_compliance_rate'] !== null && (float)$m['pos_compliance_rate'] < self::POS_COMPLIANCE_TARGET) {
                $parts[] = 'POS compliance ' . $this->formatPercent($m['pos_compliance_rate']);
            }

            if (!$parts) {
                $parts[] = 'low overall score ' . (int)($branch['overall_score'] ?? 0) . '/100';
            }

            $drivers[] = $name . ' (' . implode(', ', array_slice($parts, 0, 3)) . ')';
        }

        return $drivers;
    }

    private function classifyPortfolioForwardRisk(array $statusCounts, int $branchCount, int $riskCount): string
    {
        $criticalCount = (int)($statusCounts['CRITICAL'] ?? 0);
        $warningCount = (int)($statusCounts['WARNING'] ?? 0);
        $exposureRatio = $branchCount > 0 ? ($riskCount / $branchCount) : 0.0;

        if ($criticalCount >= 2 || $exposureRatio >= 0.45) {
            return 'HIGH';
        }
        if ($criticalCount >= 1 || $warningCount >= 2 || $exposureRatio >= 0.25) {
            return 'MEDIUM';
        }
        return 'LOW';
    }

    private function normalizeMonthKey(string $reportingPeriod): ?string
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

    private function formatMonthLabel(string $monthKey): string
    {
        $clean = trim($monthKey);
        if ($clean === '') {
            return '';
        }

        $timestamp = strtotime($clean . '-01');
        if ($timestamp === false) {
            return $clean;
        }

        return date('M Y', $timestamp);
    }

    private function buildMonthlyStatusCounts(array $month): array
    {
        $counts = [
            'EXCELLENT' => 0,
            'GOOD' => 0,
            'WARNING' => 0,
            'CRITICAL' => 0,
        ];

        $branches = (isset($month['branches']) && is_array($month['branches'])) ? $month['branches'] : [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $status = strtoupper(trim((string)($branch['status'] ?? '')));
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    private function pickBranchNamesByStatus(array $month, array $statusKeys, int $limit = 3): string
    {
        $normalizedStatusKeys = array_map(function ($item) {
            return strtolower(trim((string)$item));
        }, $statusKeys);

        $branches = (isset($month['branches']) && is_array($month['branches'])) ? $month['branches'] : [];
        $names = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $statusKey = strtolower(trim((string)($branch['status_key'] ?? '')));
            if (!in_array($statusKey, $normalizedStatusKeys, true)) {
                continue;
            }

            $name = trim((string)($branch['branch_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $names[] = $name;
            if (count($names) >= $limit) {
                break;
            }
        }

        return $this->joinLabels($names);
    }

    private function buildBranchInterpretation(array $analysis): array
    {
        $m = $analysis['metrics'];

        $lines = [];
        $lines[] = sprintf(
            '%s scored %d/100. Status: %s (%s).',
            $analysis['branch_name'],
            $analysis['overall_score'],
            $analysis['status'],
            $analysis['status_text']
        );

        $lines[] = sprintf(
            'Sales moved from %s to %s (%s growth).',
            $this->formatMoney($m['previous_sales']),
            $this->formatMoney($m['current_sales']),
            $this->formatPercent($m['sales_growth_rate'])
        );

        $lines[] = sprintf(
            'Take-home profit after costs is %s of sales, while operating costs are %s of sales.',
            $this->formatPercent($m['net_margin']),
            $this->formatPercent($m['expense_ratio'])
        );

        $lines[] = sprintf(
            'Slow-moving stock is %s of total inventory.',
            $this->formatPercent($m['dead_stock_ratio'])
        );

        $lines[] = sprintf(
            'POS entries were completed on %d of %d days (%s).',
            (int)$m['actual_pos_days'],
            (int)$m['expected_pos_days'],
            $this->formatPercent($m['pos_compliance_rate']),
        );

        $priorityActions = $this->buildPriorityActionsForBranch($analysis);
        foreach ($priorityActions as $action) {
            $lines[] = $action;
        }

        return array_slice($lines, 0, 6);
    }

    private function buildPriorityActionsForBranch(array $analysis): array
    {
        $m = $analysis['metrics'];
        $actions = [];

        if ($m['expense_ratio'] !== null && $m['expense_ratio'] > self::EXPENSE_RATIO_LIMIT) {
            $actions[] = 'Main action: reduce avoidable operating expenses.';
        }
        if ($m['dead_stock_ratio'] !== null && $m['dead_stock_ratio'] > self::DEAD_STOCK_RATIO_LIMIT) {
            $actions[] = 'Main action: clear slow-moving stock and tighten ordering.';
        }
        if ($m['pos_compliance_rate'] !== null && $m['pos_compliance_rate'] < self::POS_COMPLIANCE_TARGET) {
            $actions[] = 'Main action: complete POS entries daily without missed days.';
        }
        if ($m['sales_growth_rate'] !== null && $m['sales_growth_rate'] < self::SALES_GROWTH_TARGET) {
            $actions[] = 'Main action: run local sales pushes to improve growth.';
        }
        if ($m['net_margin'] !== null && $m['net_margin'] < self::NET_MARGIN_TARGET) {
            $actions[] = 'Main action: review pricing and cost leaks to improve take-home profit.';
        }

        if (!$actions) {
            $actions[] = 'Main action: keep the current plan and review branch KPIs weekly.';
        }

        return $actions;
    }

    private function analyzeBranch(array $branch): array
    {
        $branchName = (string)$branch['branch'];
        $detail = $this->healthService->getBranchHealthDetailFromData($branch);

        $currentSales = (float)$branch['current_sales'];
        $previousSales = (float)$branch['previous_sales'];
        $expenses = (float)$branch['expenses'];
        $cogs = (float)$branch['cogs'];
        $avgInventory = (float)$branch['avg_inventory'];
        $deadStock = (float)$branch['dead_stock'];
        $expectedPosDays = (float)$branch['expected_pos_days'];
        $actualPosDays = (float)$branch['actual_pos_days'];

        $salesGrowthRate = $previousSales > 0 ? ($currentSales - $previousSales) / $previousSales : null;
        $netIncome = $currentSales - $expenses - $cogs;
        $netMargin = $currentSales > 0 ? $netIncome / $currentSales : null;
        $expenseRatio = $currentSales > 0 ? $expenses / $currentSales : null;
        $cogsRatio = $currentSales > 0 ? $cogs / $currentSales : null;
        $deadStockRatio = $avgInventory > 0 ? $deadStock / $avgInventory : null;
        $posComplianceRate = $expectedPosDays > 0 ? $actualPosDays / $expectedPosDays : null;

        $metrics = [
            'current_sales' => $currentSales,
            'previous_sales' => $previousSales,
            'expenses' => $expenses,
            'cogs' => $cogs,
            'avg_inventory' => $avgInventory,
            'dead_stock' => $deadStock,
            'expected_pos_days' => $expectedPosDays,
            'actual_pos_days' => $actualPosDays,
            'sales_growth_rate' => $salesGrowthRate,
            'net_income' => $netIncome,
            'net_margin' => $netMargin,
            'expense_ratio' => $expenseRatio,
            'cogs_ratio' => $cogsRatio,
            'dead_stock_ratio' => $deadStockRatio,
            'pos_compliance_rate' => $posComplianceRate,
        ];
        $anomalyFlags = $this->buildAnomalyFlags($metrics);
        $severityScore = $this->computeSeverityScore($metrics, $anomalyFlags);
        $confidenceScore = $this->computeConfidenceScore($metrics, $anomalyFlags);
        $estimatedImpactPhp = $this->estimateImpactPhp($metrics);
        $forwardRisk = $this->buildForwardRiskSignals($metrics);

        return [
            'branch_name' => $branchName,
            'overall_score' => $detail ? (int)$detail['overall_score'] : 0,
            'status' => $detail ? (string)$detail['status'] : 'UNKNOWN',
            'status_text' => $detail ? (string)$detail['status_text'] : 'N/A',
            'factors' => ($detail && isset($detail['factors']) && is_array($detail['factors']))
                ? $detail['factors']
                : [],
            'metrics' => $metrics,
            'decision_signals' => [
                'anomaly_flags' => $anomalyFlags,
                'severity_score' => $severityScore,
                'confidence_score' => $confidenceScore,
                'estimated_impact_php' => $estimatedImpactPhp,
                'risk_30d' => $forwardRisk['risk_30d'],
                'risk_60d' => $forwardRisk['risk_60d'],
            ],
        ];
    }

    private function buildAnomalyFlags(array $metrics): array
    {
        $flags = [];

        $expenseRatio = $metrics['expense_ratio'] ?? null;
        $deadStockRatio = $metrics['dead_stock_ratio'] ?? null;
        $posCompliance = $metrics['pos_compliance_rate'] ?? null;
        $currentSales = (float)($metrics['current_sales'] ?? 0.0);
        $expenses = (float)($metrics['expenses'] ?? 0.0);

        if ($currentSales <= 0 && $expenses > 0) {
            $flags[] = 'sales_zero_with_positive_expenses';
        }
        if ($expenseRatio !== null && (float)$expenseRatio > self::EXPENSE_RATIO_ANOMALY_LIMIT) {
            $flags[] = 'expense_ratio_extreme';
        }
        if ($deadStockRatio !== null && (float)$deadStockRatio > self::DEAD_STOCK_RATIO_ANOMALY_LIMIT) {
            $flags[] = 'dead_stock_ratio_extreme';
        }
        if ($posCompliance !== null && ((float)$posCompliance < 0.0 || (float)$posCompliance > 1.0)) {
            $flags[] = 'pos_compliance_out_of_range';
        }

        return $flags;
    }

    private function computeSeverityScore(array $metrics, array $anomalyFlags): int
    {
        $score = 0.0;

        $salesGrowth = $metrics['sales_growth_rate'] ?? null;
        $netMargin = $metrics['net_margin'] ?? null;
        $expenseRatio = $metrics['expense_ratio'] ?? null;
        $deadStockRatio = $metrics['dead_stock_ratio'] ?? null;
        $posCompliance = $metrics['pos_compliance_rate'] ?? null;
        $netIncome = (float)($metrics['net_income'] ?? 0.0);

        if ($salesGrowth !== null && (float)$salesGrowth < self::SALES_GROWTH_TARGET) {
            $gap = max(0.0, self::SALES_GROWTH_TARGET - (float)$salesGrowth);
            $score += min(15.0, $gap * 100.0);
        }
        if ($netMargin !== null && (float)$netMargin < self::NET_MARGIN_TARGET) {
            $gap = max(0.0, self::NET_MARGIN_TARGET - (float)$netMargin);
            $score += min(25.0, $gap * 120.0);
        }
        if ($expenseRatio !== null && (float)$expenseRatio > self::EXPENSE_RATIO_LIMIT) {
            $gap = max(0.0, (float)$expenseRatio - self::EXPENSE_RATIO_LIMIT);
            $score += min(25.0, $gap * 100.0);
        }
        if ($deadStockRatio !== null && (float)$deadStockRatio > self::DEAD_STOCK_RATIO_LIMIT) {
            $gap = max(0.0, (float)$deadStockRatio - self::DEAD_STOCK_RATIO_LIMIT);
            $score += min(15.0, $gap * 100.0);
        }
        if ($posCompliance !== null && (float)$posCompliance < self::POS_COMPLIANCE_TARGET) {
            $gap = max(0.0, self::POS_COMPLIANCE_TARGET - (float)$posCompliance);
            $score += min(10.0, $gap * 100.0);
        }
        if ($netIncome < 0) {
            $score += 20.0;
        }

        $score += min(20.0, count($anomalyFlags) * 10.0);
        return (int)max(0, min(100, round($score)));
    }

    private function computeConfidenceScore(array $metrics, array $anomalyFlags): float
    {
        $score = 0.90;
        $requiredKeys = [
            'current_sales',
            'previous_sales',
            'expenses',
            'cogs',
            'avg_inventory',
            'dead_stock',
            'expected_pos_days',
            'actual_pos_days',
        ];

        $missingCount = 0;
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $metrics)) {
                $missingCount++;
            }
        }

        $score -= min(0.20, $missingCount * 0.03);
        $score -= min(0.45, count($anomalyFlags) * 0.15);

        return (float)max(0.10, min(0.99, round($score, 2)));
    }

    private function estimateImpactPhp(array $metrics): float
    {
        $currentSales = (float)($metrics['current_sales'] ?? 0.0);
        $expenses = (float)($metrics['expenses'] ?? 0.0);
        $deadStock = (float)($metrics['dead_stock'] ?? 0.0);
        $netIncome = (float)($metrics['net_income'] ?? 0.0);

        $targetExpense = $currentSales * self::EXPENSE_RATIO_LIMIT;
        $excessExpense = max(0.0, $expenses - $targetExpense);

        $targetNetIncome = $currentSales * self::NET_MARGIN_TARGET;
        $marginShortfall = max(0.0, $targetNetIncome - $netIncome);

        $deadStockCapitalPressure = max(0.0, $deadStock * 0.20);

        $impact = max($excessExpense, $marginShortfall) + $deadStockCapitalPressure;
        return round($impact, 2);
    }

    private function buildForwardRiskSignals(array $metrics): array
    {
        $projected30 = $this->projectOnePeriod($metrics);
        $projected60 = $this->projectOnePeriod($projected30);

        return [
            'risk_30d' => [
                'level' => $this->classifyProjectedRiskLevel($projected30),
                'projected_net_income' => round((float)($projected30['net_income'] ?? 0.0), 2),
                'projected_net_margin' => round((float)($projected30['net_margin'] ?? 0.0), 4),
            ],
            'risk_60d' => [
                'level' => $this->classifyProjectedRiskLevel($projected60),
                'projected_net_income' => round((float)($projected60['net_income'] ?? 0.0), 2),
                'projected_net_margin' => round((float)($projected60['net_margin'] ?? 0.0), 4),
            ],
        ];
    }

    private function projectOnePeriod(array $metrics): array
    {
        $currentSales = (float)($metrics['current_sales'] ?? 0.0);
        $salesGrowth = isset($metrics['sales_growth_rate']) ? (float)$metrics['sales_growth_rate'] : 0.0;
        $expenseRatio = isset($metrics['expense_ratio']) ? (float)$metrics['expense_ratio'] : 0.0;
        $cogsRatio = isset($metrics['cogs_ratio']) ? (float)$metrics['cogs_ratio'] : 0.0;
        $deadStockRatio = isset($metrics['dead_stock_ratio']) ? (float)$metrics['dead_stock_ratio'] : 0.0;
        $avgInventory = (float)($metrics['avg_inventory'] ?? 0.0);
        $expectedPosDays = (float)($metrics['expected_pos_days'] ?? 0.0);
        $actualPosDays = (float)($metrics['actual_pos_days'] ?? 0.0);

        $projectedSales = $currentSales * (1.0 + $salesGrowth);
        if ($projectedSales < 0) {
            $projectedSales = 0.0;
        }

        $projectedExpenses = $projectedSales * $expenseRatio;
        $projectedCogs = $projectedSales * $cogsRatio;
        $projectedNetIncome = $projectedSales - $projectedExpenses - $projectedCogs;
        $projectedNetMargin = $projectedSales > 0
            ? $projectedNetIncome / $projectedSales
            : 0.0;

        return [
            'current_sales' => $projectedSales,
            'previous_sales' => $currentSales,
            'expenses' => $projectedExpenses,
            'cogs' => $projectedCogs,
            'avg_inventory' => $avgInventory,
            'dead_stock' => $avgInventory * $deadStockRatio,
            'expected_pos_days' => $expectedPosDays,
            'actual_pos_days' => $actualPosDays,
            'sales_growth_rate' => $salesGrowth,
            'net_income' => $projectedNetIncome,
            'net_margin' => $projectedNetMargin,
            'expense_ratio' => $expenseRatio,
            'cogs_ratio' => $cogsRatio,
            'dead_stock_ratio' => $deadStockRatio,
            'pos_compliance_rate' => $expectedPosDays > 0 ? ($actualPosDays / $expectedPosDays) : null,
        ];
    }

    private function classifyProjectedRiskLevel(array $projected): string
    {
        $netIncome = (float)($projected['net_income'] ?? 0.0);
        $netMargin = (float)($projected['net_margin'] ?? 0.0);
        $expenseRatio = (float)($projected['expense_ratio'] ?? 0.0);

        if ($netIncome < 0 || $expenseRatio > self::EXPENSE_RATIO_LIMIT || $netMargin < 0.05) {
            return 'HIGH';
        }
        if ($netMargin < self::NET_MARGIN_TARGET || $expenseRatio > 0.55) {
            return 'MEDIUM';
        }
        return 'LOW';
    }

    private function topBranchesForIssue(array $branches, callable $gapFn, int $limit = 3): array
    {
        $copy = $branches;
        usort($copy, function ($a, $b) use ($gapFn) {
            return $gapFn($b) <=> $gapFn($a);
        });

        return array_slice($copy, 0, $limit);
    }

    private function joinBranchNames(array $branches): string
    {
        $names = array_map(function ($item) {
            return $item['branch_name'];
        }, $branches);

        return $this->joinLabels($names);
    }

    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(array_map('strval', $labels), function ($label) {
            return trim($label) !== '';
        }));

        $count = count($labels);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0] . ' and ' . $labels[1];
        }

        $last = array_pop($labels);
        return implode(', ', $labels) . ', and ' . $last;
    }

    private function formatMetricList(array $branches, string $metricKey): string
    {
        $parts = [];
        foreach ($branches as $branch) {
            $value = $branch['metrics'][$metricKey] ?? null;
            $parts[] = $branch['branch_name'] . ' (' . $this->formatPercent($value) . ')';
        }
        return implode(', ', $parts);
    }

    private function resolveOverviewMonthKey(): string
    {
        $reports = $this->branchRepository->getMonthlyReports(null, 1);
        if (is_array($reports) && !empty($reports)) {
            $first = $reports[0];
            if (is_array($first) && isset($first['reporting_period'])) {
                $ts = strtotime((string)$first['reporting_period']);
                if ($ts !== false) {
                    return date('Y-m', $ts);
                }
            }
        }

        return date('Y-m');
    }

    private function buildOverviewCacheHash(array $analysis, array $portfolio): string
    {
        $payload = $this->buildImpactAwareOverviewHashPayload($analysis, $portfolio);
        return $this->hashPayloadForCache($payload);
    }

    private function buildImpactAwareOverviewHashPayload(array $analysis, array $portfolio): array
    {
        $branches = [];
        foreach ($analysis as $item) {
            if (!is_array($item)) {
                continue;
            }
            $branches[] = $this->buildImpactAwareBranchHashPayload($item);
        }

        usort($branches, function (array $a, array $b): int {
            return strcmp((string)($a['branch_name'] ?? ''), (string)($b['branch_name'] ?? ''));
        });

        $issueBuckets = (isset($portfolio['issue_buckets']) && is_array($portfolio['issue_buckets']))
            ? $portfolio['issue_buckets']
            : [];
        $statusCounts = (isset($portfolio['status_counts']) && is_array($portfolio['status_counts']))
            ? $portfolio['status_counts']
            : [];
        $decisionOverview = (isset($portfolio['decision_overview']) && is_array($portfolio['decision_overview']))
            ? $portfolio['decision_overview']
            : [];

        return [
            'schema' => 'overview_impact_v1',
            'impact_hash_config' => [
                'money_step' => $this->resolveImpactHashMoneyStep(),
                'percent_point_step' => $this->resolveImpactHashPercentPointStep(),
                'score_step' => $this->resolveImpactHashScoreStep(),
            ],
            'branch_count' => (int)($portfolio['branch_count'] ?? count($branches)),
            'average_score_bucket' => $this->quantizeScoreForImpactHash($portfolio['average_score'] ?? 0),
            'status_counts' => [
                'EXCELLENT' => (int)($statusCounts['EXCELLENT'] ?? 0),
                'GOOD' => (int)($statusCounts['GOOD'] ?? 0),
                'WARNING' => (int)($statusCounts['WARNING'] ?? 0),
                'CRITICAL' => (int)($statusCounts['CRITICAL'] ?? 0),
            ],
            'issue_counts' => [
                'weak_sales_growth' => isset($issueBuckets['weak_sales_growth']) && is_array($issueBuckets['weak_sales_growth'])
                    ? count($issueBuckets['weak_sales_growth'])
                    : 0,
                'low_net_margin' => isset($issueBuckets['low_net_margin']) && is_array($issueBuckets['low_net_margin'])
                    ? count($issueBuckets['low_net_margin'])
                    : 0,
                'high_expense_ratio' => isset($issueBuckets['high_expense_ratio']) && is_array($issueBuckets['high_expense_ratio'])
                    ? count($issueBuckets['high_expense_ratio'])
                    : 0,
                'high_dead_stock' => isset($issueBuckets['high_dead_stock']) && is_array($issueBuckets['high_dead_stock'])
                    ? count($issueBuckets['high_dead_stock'])
                    : 0,
                'low_pos_compliance' => isset($issueBuckets['low_pos_compliance']) && is_array($issueBuckets['low_pos_compliance'])
                    ? count($issueBuckets['low_pos_compliance'])
                    : 0,
            ],
            'portfolio_metrics' => [
                'growth_pp_bucket' => $this->quantizePercentPointsForImpactHash($portfolio['portfolio_growth'] ?? null),
                'net_margin_pp_bucket' => $this->quantizePercentPointsForImpactHash($portfolio['portfolio_net_margin'] ?? null),
                'expense_ratio_pp_bucket' => $this->quantizePercentPointsForImpactHash($portfolio['portfolio_expense_ratio'] ?? null),
                'dead_stock_ratio_pp_bucket' => $this->quantizePercentPointsForImpactHash($portfolio['portfolio_dead_stock_ratio'] ?? null),
                'pos_compliance_pp_bucket' => $this->quantizePercentPointsForImpactHash($portfolio['portfolio_pos_compliance'] ?? null),
            ],
            'decision_overview' => [
                'anomaly_branch_count' => (int)($decisionOverview['anomaly_branch_count'] ?? 0),
                'high_risk_branch_count' => (int)($decisionOverview['high_risk_branch_count'] ?? 0),
                'total_estimated_impact_php_bucket' => $this->quantizeMoneyForImpactHash(
                    (float)($decisionOverview['total_estimated_impact_php'] ?? 0.0)
                ),
                'average_severity_score_bucket' => $this->quantizeScoreForImpactHash(
                    (float)($decisionOverview['average_severity_score'] ?? 0.0)
                ),
                'average_confidence_score_bucket' => round((float)($decisionOverview['average_confidence_score'] ?? 0.0), 2),
            ],
            'top_branch' => $this->buildImpactAwareTopBottomBranchHashPayload($portfolio['top_branch'] ?? null),
            'bottom_branch' => $this->buildImpactAwareTopBottomBranchHashPayload($portfolio['bottom_branch'] ?? null),
            'branches' => $branches,
        ];
    }

    private function buildImpactAwareTopBottomBranchHashPayload($branch): ?array
    {
        if (!is_array($branch)) {
            return null;
        }

        return [
            'branch_name' => strtolower(trim((string)($branch['branch_name'] ?? ''))),
            'overall_score_bucket' => $this->quantizeScoreForImpactHash($branch['overall_score'] ?? 0),
            'status' => strtoupper(trim((string)($branch['status'] ?? ''))),
        ];
    }

    private function buildImpactAwareBranchHashPayload(array $analysis): array
    {
        $metrics = (isset($analysis['metrics']) && is_array($analysis['metrics']))
            ? $analysis['metrics']
            : [];
        $signals = (isset($analysis['decision_signals']) && is_array($analysis['decision_signals']))
            ? $analysis['decision_signals']
            : [];
        $risk30d = (isset($signals['risk_30d']) && is_array($signals['risk_30d']))
            ? $signals['risk_30d']
            : [];
        $risk60d = (isset($signals['risk_60d']) && is_array($signals['risk_60d']))
            ? $signals['risk_60d']
            : [];

        $salesGrowth = isset($metrics['sales_growth_rate']) && is_numeric($metrics['sales_growth_rate'])
            ? (float)$metrics['sales_growth_rate']
            : null;
        $netMargin = isset($metrics['net_margin']) && is_numeric($metrics['net_margin'])
            ? (float)$metrics['net_margin']
            : null;
        $expenseRatio = isset($metrics['expense_ratio']) && is_numeric($metrics['expense_ratio'])
            ? (float)$metrics['expense_ratio']
            : null;
        $deadStockRatio = isset($metrics['dead_stock_ratio']) && is_numeric($metrics['dead_stock_ratio'])
            ? (float)$metrics['dead_stock_ratio']
            : null;
        $posCompliance = isset($metrics['pos_compliance_rate']) && is_numeric($metrics['pos_compliance_rate'])
            ? (float)$metrics['pos_compliance_rate']
            : null;

        $anomalyFlags = (isset($signals['anomaly_flags']) && is_array($signals['anomaly_flags']))
            ? array_values(array_filter(array_map(function ($flag): string {
                return trim((string)$flag);
            }, $signals['anomaly_flags']), function (string $flag): bool {
                return $flag !== '';
            }))
            : [];
        sort($anomalyFlags);

        return [
            'branch_name' => strtolower(trim((string)($analysis['branch_name'] ?? ''))),
            'overall_score_bucket' => $this->quantizeScoreForImpactHash($analysis['overall_score'] ?? 0),
            'status' => strtoupper(trim((string)($analysis['status'] ?? 'UNKNOWN'))),
            'factor_scores' => $this->normalizeFactorScoresForImpactHash(
                isset($analysis['factors']) && is_array($analysis['factors']) ? $analysis['factors'] : []
            ),
            'metrics' => [
                'current_sales_bucket' => $this->quantizeMoneyForImpactHash((float)($metrics['current_sales'] ?? 0.0)),
                'previous_sales_bucket' => $this->quantizeMoneyForImpactHash((float)($metrics['previous_sales'] ?? 0.0)),
                'net_income_bucket' => $this->quantizeMoneyForImpactHash((float)($metrics['net_income'] ?? 0.0)),
                'sales_growth_pp_bucket' => $this->quantizePercentPointsForImpactHash($salesGrowth),
                'net_margin_pp_bucket' => $this->quantizePercentPointsForImpactHash($netMargin),
                'expense_ratio_pp_bucket' => $this->quantizePercentPointsForImpactHash($expenseRatio),
                'dead_stock_ratio_pp_bucket' => $this->quantizePercentPointsForImpactHash($deadStockRatio),
                'pos_compliance_pp_bucket' => $this->quantizePercentPointsForImpactHash($posCompliance),
                'actual_pos_days' => isset($metrics['actual_pos_days']) ? (int)$metrics['actual_pos_days'] : 0,
                'expected_pos_days' => isset($metrics['expected_pos_days']) ? (int)$metrics['expected_pos_days'] : 0,
            ],
            'threshold_flags' => [
                'weak_sales_growth' => $salesGrowth !== null ? ($salesGrowth < self::SALES_GROWTH_TARGET) : null,
                'low_net_margin' => $netMargin !== null ? ($netMargin < self::NET_MARGIN_TARGET) : null,
                'high_expense_ratio' => $expenseRatio !== null ? ($expenseRatio > self::EXPENSE_RATIO_LIMIT) : null,
                'high_dead_stock' => $deadStockRatio !== null ? ($deadStockRatio > self::DEAD_STOCK_RATIO_LIMIT) : null,
                'low_pos_compliance' => $posCompliance !== null ? ($posCompliance < self::POS_COMPLIANCE_TARGET) : null,
            ],
            'decision_signals' => [
                'anomaly_flags' => $anomalyFlags,
                'severity_score_bucket' => $this->quantizeScoreForImpactHash($signals['severity_score'] ?? 0),
                'confidence_score_bucket' => round((float)($signals['confidence_score'] ?? 0.0), 2),
                'estimated_impact_php_bucket' => $this->quantizeMoneyForImpactHash(
                    (float)($signals['estimated_impact_php'] ?? 0.0)
                ),
                'risk_30d' => strtoupper(trim((string)($risk30d['level'] ?? 'LOW'))),
                'risk_60d' => strtoupper(trim((string)($risk60d['level'] ?? 'LOW'))),
            ],
        ];
    }

    private function normalizeFactorScoresForImpactHash(array $factors): array
    {
        $normalized = [];
        foreach ($factors as $factor) {
            if (!is_array($factor)) {
                continue;
            }
            $name = trim((string)($factor['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => strtolower($name),
                'score_bucket' => $this->quantizeScoreForImpactHash($factor['score'] ?? 0),
                'weight' => isset($factor['weight']) ? (int)$factor['weight'] : 0,
            ];
        }

        usort($normalized, function (array $a, array $b): int {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $normalized;
    }

    private function quantizeMoneyForImpactHash(float $value): float
    {
        $step = $this->resolveImpactHashMoneyStep();
        if ($step <= 0.0) {
            return round($value, 2);
        }

        return round(round($value / $step) * $step, 2);
    }

    private function quantizePercentPointsForImpactHash($ratio): ?float
    {
        if ($ratio === null || $ratio === '' || !is_numeric($ratio)) {
            return null;
        }

        $step = $this->resolveImpactHashPercentPointStep();
        $points = ((float)$ratio) * 100.0;
        if ($step <= 0.0) {
            return round($points, 2);
        }

        return round(round($points / $step) * $step, 2);
    }

    private function quantizeScoreForImpactHash($score): int
    {
        $value = is_numeric($score) ? (float)$score : 0.0;
        $step = max(1, $this->resolveImpactHashScoreStep());
        return (int)round(round($value / $step) * $step);
    }

    private function resolveImpactHashMoneyStep(): float
    {
        return $this->readEnvPositiveFloat('AI_CACHE_IMPACT_MONEY_STEP', 5000.0);
    }

    private function resolveImpactHashPercentPointStep(): float
    {
        return $this->readEnvPositiveFloat('AI_CACHE_IMPACT_PERCENT_POINT_STEP', 0.5);
    }

    private function resolveImpactHashScoreStep(): int
    {
        return $this->readEnvPositiveInt('AI_CACHE_IMPACT_SCORE_STEP', 1);
    }

    private function readEnvPositiveFloat(string $key, float $default): float
    {
        $fallback = ($default > 0.0) ? $default : 0.0001;
        $raw = trim($this->readEnv($key, ''));
        if ($raw === '' || !is_numeric($raw)) {
            return $fallback;
        }

        $value = (float)$raw;
        return $value > 0.0 ? $value : $fallback;
    }

    private function readEnvPositiveInt(string $key, int $default): int
    {
        $fallback = max(1, $default);
        $raw = trim($this->readEnv($key, ''));
        if ($raw === '' || !is_numeric($raw)) {
            return $fallback;
        }

        $value = (int)$raw;
        return $value > 0 ? $value : $fallback;
    }

    private function hashPayloadForCache(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json !== false ? $json : serialize($payload));
    }

    private function shouldReuseMonthlyCache(
        ?array $cacheRow,
        string $expectedDataHash,
        string $monthKey,
        bool $freezeHistorical = false
    ): bool
    {
        if (!is_array($cacheRow)) {
            return false;
        }

        $payload = $this->extractCachePayload($cacheRow);
        if ($payload === null) {
            return false;
        }

        // For history narratives, once period is historical and has cached payload,
        // treat it as finalized snapshot and stop regenerating.
        if ($freezeHistorical && $this->isPastMonthKey($monthKey)) {
            $updatedAt = isset($cacheRow['updated_at']) ? (string)$cacheRow['updated_at'] : null;
            if ($this->isMonthlyCacheFinalized($monthKey, $updatedAt)) {
                return true;
            }
        }

        $cachedDataHash = trim((string)($cacheRow['data_hash'] ?? ''));
        if ($cachedDataHash === '' || !hash_equals($cachedDataHash, $expectedDataHash)) {
            return false;
        }

        // Historical months can still be reused when data hash is unchanged.
        if ($this->isPastMonthKey($monthKey)) {
            return !$freezeHistorical;
        }

        return !$this->shouldForceMonthEndRefresh(
            $monthKey,
            isset($cacheRow['updated_at']) ? (string)$cacheRow['updated_at'] : null
        );
    }

    private function shouldForceMonthEndRefresh(string $monthKey, ?string $updatedAt): bool
    {
        if (!$this->isMonthEndRefreshEnabled()) {
            return false;
        }

        if (!$this->isMonthEndRefreshWindow($monthKey)) {
            return false;
        }

        $today = new DateTimeImmutable('now');

        if ($updatedAt === null || trim($updatedAt) === '') {
            return true;
        }

        $updatedTs = strtotime($updatedAt);
        if ($updatedTs === false) {
            return true;
        }

        return date('Y-m-d', $updatedTs) !== $today->format('Y-m-d');
    }

    private function isMonthEndRefreshWindow(string $monthKey): bool
    {
        if ($this->isMonthEndRefreshForced()) {
            return true;
        }

        if (!$this->isMonthEndRefreshEnabled()) {
            return true;
        }

        if (!preg_match('/^\d{4}-\d{2}$/', trim($monthKey))) {
            return false;
        }

        $today = new DateTimeImmutable('now');
        return $today->format('Y-m') === $monthKey
            && $today->format('d') === $today->format('t');
    }

    private function isPastMonthKey(string $monthKey): bool
    {
        if (!preg_match('/^\d{4}-\d{2}$/', trim($monthKey))) {
            return false;
        }

        $currentMonthKey = (new DateTimeImmutable('now'))->format('Y-m');
        return strcmp($monthKey, $currentMonthKey) < 0;
    }

    private function isMonthlyCacheFinalized(string $monthKey, ?string $updatedAt): bool
    {
        if (!preg_match('/^\d{4}-\d{2}$/', trim($monthKey))) {
            return false;
        }
        if ($updatedAt === null || trim($updatedAt) === '') {
            return false;
        }

        $updatedTs = strtotime($updatedAt);
        if ($updatedTs === false) {
            return false;
        }

        $periodStartTs = strtotime($monthKey . '-01');
        if ($periodStartTs === false) {
            return false;
        }
        $periodEndDate = date('Y-m-t', $periodStartTs);

        return date('Y-m-d', $updatedTs) >= $periodEndDate;
    }

    private function canWriteMonthlyCache(string $monthKey): bool
    {
        // Persist cache on every run so repeated requests can reuse the same AI response.
        // Month-end refresh settings are enforced in reuse checks, not write eligibility.
        return true;
    }

    private function isMonthEndRefreshEnabled(): bool
    {
        $raw = strtolower(trim($this->readEnv('AI_MONTH_END_CACHE_REFRESH', '1')));
        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    private function isMonthEndRefreshForced(): bool
    {
        $raw = strtolower(trim($this->readEnv('AI_MONTH_END_CACHE_REFRESH_FORCE', '0')));
        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function shouldReuseYearlyCache(
        ?array $cacheRow,
        string $expectedDataHash,
        string $yearKey,
        bool $freezeHistorical = false
    ): bool
    {
        if (!is_array($cacheRow)) {
            return false;
        }

        $payload = $this->extractCachePayload($cacheRow);
        if ($payload === null) {
            return false;
        }

        // For history narratives, once period is historical and has cached payload,
        // treat it as finalized snapshot and stop regenerating.
        if ($freezeHistorical && $this->isPastYearKey($yearKey)) {
            $updatedAt = isset($cacheRow['updated_at']) ? (string)$cacheRow['updated_at'] : null;
            if ($this->isYearlyCacheFinalized($yearKey, $updatedAt)) {
                return true;
            }
        }

        $cachedDataHash = trim((string)($cacheRow['data_hash'] ?? ''));
        if ($cachedDataHash === '' || !hash_equals($cachedDataHash, $expectedDataHash)) {
            return false;
        }

        // Historical years can still be reused when data hash is unchanged.
        if ($this->isPastYearKey($yearKey)) {
            return !$freezeHistorical;
        }

        return !$this->shouldForceYearEndRefresh(
            $yearKey,
            isset($cacheRow['updated_at']) ? (string)$cacheRow['updated_at'] : null
        );
    }

    private function shouldForceYearEndRefresh(string $yearKey, ?string $updatedAt): bool
    {
        if (!$this->isYearEndRefreshEnabled()) {
            return false;
        }

        if (!$this->isYearEndRefreshWindow($yearKey)) {
            return false;
        }

        $today = new DateTimeImmutable('now');

        if ($updatedAt === null || trim($updatedAt) === '') {
            return true;
        }

        $updatedTs = strtotime($updatedAt);
        if ($updatedTs === false) {
            return true;
        }

        return date('Y-m-d', $updatedTs) !== $today->format('Y-m-d');
    }

    private function isYearEndRefreshWindow(string $yearKey): bool
    {
        if ($this->isYearEndRefreshForced()) {
            return true;
        }

        if (!$this->isYearEndRefreshEnabled()) {
            return true;
        }

        if (!preg_match('/^\d{4}$/', trim($yearKey))) {
            return false;
        }

        $today = new DateTimeImmutable('now');
        return $today->format('Y') === $yearKey
            && $today->format('d') === $today->format('t');
    }

    private function isPastYearKey(string $yearKey): bool
    {
        if (!preg_match('/^\d{4}$/', trim($yearKey))) {
            return false;
        }

        $currentYearKey = (new DateTimeImmutable('now'))->format('Y');
        return strcmp($yearKey, $currentYearKey) < 0;
    }

    private function isYearlyCacheFinalized(string $yearKey, ?string $updatedAt): bool
    {
        if (!preg_match('/^\d{4}$/', trim($yearKey))) {
            return false;
        }
        if ($updatedAt === null || trim($updatedAt) === '') {
            return false;
        }

        $updatedTs = strtotime($updatedAt);
        if ($updatedTs === false) {
            return false;
        }

        $periodEndDate = $yearKey . '-12-31';
        return date('Y-m-d', $updatedTs) >= $periodEndDate;
    }

    private function canWriteYearlyCache(string $yearKey): bool
    {
        // Persist cache on every run so repeated requests can reuse the same AI response.
        // Year-end refresh settings are enforced in reuse checks, not write eligibility.
        return true;
    }

    private function isYearEndRefreshEnabled(): bool
    {
        $raw = strtolower(trim($this->readEnv('AI_YEAR_END_CACHE_REFRESH', '1')));
        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    private function isYearEndRefreshForced(): bool
    {
        $raw = strtolower(trim($this->readEnv('AI_YEAR_END_CACHE_REFRESH_FORCE', '0')));
        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function extractCachePayload(?array $cacheRow): ?array
    {
        if (!is_array($cacheRow)) {
            return null;
        }

        $payload = $cacheRow['payload_json'] ?? null;
        return is_array($payload) ? $payload : null;
    }

    private function readOverviewCache(string $monthKey): ?array
    {
        $row = $this->readOverviewCacheRow($monthKey);
        return $this->extractCachePayload($row);
    }

    private function readOverviewCacheRow(string $monthKey): ?array
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->cacheTable === '') {
            return null;
        }

        $query = [
            'select' => '*',
            'scope' => 'eq.overview',
            'month_key' => 'eq.' . $monthKey,
            'branch_name' => 'is.null',
            'order' => 'updated_at.desc',
            'limit' => '1',
        ];

        $rows = $this->cacheRequest($this->cacheTable, 'GET', $query, null);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function writeOverviewCache(string $monthKey, string $dataHash, array $payload): void
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->cacheTable === '') {
            return;
        }
        if (!$this->canWriteMonthlyCache($monthKey)) {
            return;
        }

        $body = [[
            'scope' => 'overview',
            'month_key' => $monthKey,
            'branch_name' => null,
            'data_hash' => $dataHash,
            'payload_json' => $payload,
            'source' => 'ai',
            'model' => $this->readEnv('GROQ_MODEL', ''),
        ]];

        // Best-effort write; ignore duplicate/conflict failures.
        $this->cacheRequest($this->cacheTable, 'POST', ['select' => 'id'], $body);
    }

    private function resolveBranchMonthKey(string $branchName): string
    {
        $reports = $this->branchRepository->getMonthlyReports($branchName, 1);
        if (is_array($reports) && !empty($reports)) {
            $first = $reports[0];
            if (is_array($first) && isset($first['reporting_period'])) {
                $ts = strtotime((string)$first['reporting_period']);
                if ($ts !== false) {
                    return date('Y-m', $ts);
                }
            }
        }

        return $this->resolveOverviewMonthKey();
    }

    private function buildBranchInterpretationCacheHash(array $analysis): string
    {
        $payload = [
            'schema' => 'branch_interpretation_impact_v1',
            'impact_hash_config' => [
                'money_step' => $this->resolveImpactHashMoneyStep(),
                'percent_point_step' => $this->resolveImpactHashPercentPointStep(),
                'score_step' => $this->resolveImpactHashScoreStep(),
            ],
            'branch' => $this->buildImpactAwareBranchHashPayload($analysis),
        ];

        return $this->hashPayloadForCache($payload);
    }

    private function readBranchInterpretationCache(string $monthKey, string $branchName): ?array
    {
        $row = $this->readBranchInterpretationCacheRow($monthKey, $branchName);
        return $this->extractCachePayload($row);
    }

    private function readBranchInterpretationCacheRow(string $monthKey, string $branchName): ?array
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->branchCacheTable === '') {
            return null;
        }

        $query = [
            'select' => '*',
            'scope' => 'eq.branch_interpretation',
            'month_key' => 'eq.' . $monthKey,
            'branch_name' => 'eq.' . $branchName,
            'order' => 'updated_at.desc',
            'limit' => '1',
        ];

        $rows = $this->cacheRequest($this->branchCacheTable, 'GET', $query, null);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function readLatestBranchInterpretationCacheRow(string $branchName): ?array
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->branchCacheTable === '') {
            return null;
        }

        $query = [
            'select' => '*',
            'scope' => 'eq.branch_interpretation',
            'branch_name' => 'eq.' . $branchName,
            'order' => 'month_key.desc,updated_at.desc',
            'limit' => '1',
        ];

        $rows = $this->cacheRequest($this->branchCacheTable, 'GET', $query, null);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function writeBranchInterpretationCache(string $monthKey, string $branchName, string $dataHash, array $payload): void
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->branchCacheTable === '') {
            return;
        }
        if (!$this->canWriteMonthlyCache($monthKey)) {
            return;
        }

        $body = [[
            'scope' => 'branch_interpretation',
            'month_key' => $monthKey,
            'branch_name' => $branchName,
            'data_hash' => $dataHash,
            'payload_json' => $payload,
            'source' => 'ai',
            'model' => $this->readEnv('GROQ_MODEL', ''),
        ]];

        $this->cacheRequest($this->branchCacheTable, 'POST', ['select' => 'id'], $body);
    }

    private function buildMonthlyNarrativeCacheHash(array $selectedMonth, ?array $branchContext, string $resolvedBranchName, ?array $portfolioContext = null): string
    {
        $payload = [
            'month' => $selectedMonth,
            'branch_context' => $branchContext,
            'resolved_branch_name' => $resolvedBranchName,
            'portfolio_context' => $portfolioContext,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json !== false ? $json : serialize($payload));
    }

    private function buildYearlyNarrativeCacheHash(array $selectedYear, ?array $previousYear, string $resolvedBranchName): string
    {
        $payload = [
            'year' => $selectedYear,
            'previous_year' => $previousYear,
            'resolved_branch_name' => $resolvedBranchName,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json !== false ? $json : serialize($payload));
    }

    private function readMonthlyNarrativeCache(string $monthKey, ?string $branchName): ?array
    {
        $row = $this->readMonthlyNarrativeCacheRow($monthKey, $branchName);
        return $this->extractCachePayload($row);
    }

    private function readMonthlyNarrativeCacheRow(string $monthKey, ?string $branchName): ?array
    {
        $table = $this->resolveMonthlyNarrativeCacheTable($branchName);
        $scope = $this->resolveMonthlyNarrativeScope($branchName);
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $table === '') {
            return null;
        }

        $query = [
            'select' => '*',
            'scope' => 'eq.' . $scope,
            'month_key' => 'eq.' . $monthKey,
            'order' => 'updated_at.desc',
            'limit' => '1',
        ];
        if ($branchName === null || trim($branchName) === '') {
            $query['branch_name'] = 'is.null';
        } else {
            $query['branch_name'] = 'eq.' . $branchName;
        }

        $rows = $this->cacheRequest($table, 'GET', $query, null);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function readYearlyNarrativeCache(string $yearKey, ?string $branchName): ?array
    {
        $row = $this->readYearlyNarrativeCacheRow($yearKey, $branchName);
        return $this->extractCachePayload($row);
    }

    private function readYearlyNarrativeCacheRow(string $yearKey, ?string $branchName): ?array
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->yearlyNarrativeCacheTable === '') {
            return null;
        }

        $query = [
            'select' => '*',
            'scope' => 'eq.yearly_narrative',
            'month_key' => 'eq.' . $yearKey,
            'order' => 'updated_at.desc',
            'limit' => '1',
        ];
        if ($branchName === null || trim($branchName) === '') {
            $query['branch_name'] = 'is.null';
        } else {
            $query['branch_name'] = 'eq.' . $branchName;
        }

        $rows = $this->cacheRequest($this->yearlyNarrativeCacheTable, 'GET', $query, null);
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    private function writeMonthlyNarrativeCache(string $monthKey, ?string $branchName, string $dataHash, array $payload): void
    {
        $table = $this->resolveMonthlyNarrativeCacheTable($branchName);
        $scope = $this->resolveMonthlyNarrativeScope($branchName);
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $table === '') {
            return;
        }
        if (!$this->canWriteMonthlyCache($monthKey)) {
            return;
        }

        $body = [[
            'scope' => $scope,
            'month_key' => $monthKey,
            'branch_name' => ($branchName !== null && trim($branchName) !== '') ? $branchName : null,
            'data_hash' => $dataHash,
            'payload_json' => $payload,
            'source' => 'ai',
            'model' => $this->readEnv('GROQ_MODEL', ''),
        ]];

        $this->cacheRequest($table, 'POST', ['select' => 'id'], $body);
    }

    private function resolveMonthlyNarrativeCacheTable(?string $branchName): string
    {
        if ($branchName !== null && trim($branchName) !== '') {
            $branchTable = trim((string)$this->monthlyBranchNarrativeCacheTable);
            if ($branchTable !== '') {
                return $branchTable;
            }
        }

        return $this->monthlyNarrativeCacheTable;
    }

    private function resolveMonthlyNarrativeScope(?string $branchName): string
    {
        return ($branchName !== null && trim($branchName) !== '')
            ? 'monthly_narrative_branch'
            : 'monthly_narrative';
    }

    private function writeYearlyNarrativeCache(string $yearKey, ?string $branchName, string $dataHash, array $payload): void
    {
        if ($this->cacheBaseUrl === '' || $this->cacheApiKey === '' || $this->yearlyNarrativeCacheTable === '') {
            return;
        }
        if (!$this->canWriteYearlyCache($yearKey)) {
            return;
        }

        $body = [[
            'scope' => 'yearly_narrative',
            'month_key' => $yearKey,
            'branch_name' => ($branchName !== null && trim($branchName) !== '') ? $branchName : null,
            'data_hash' => $dataHash,
            'payload_json' => $payload,
            'source' => 'ai',
            'model' => $this->readEnv('GROQ_MODEL', ''),
        ]];

        $this->cacheRequest($this->yearlyNarrativeCacheTable, 'POST', ['select' => 'id'], $body);
    }

    private function cacheRequest(string $table, string $method, array $query, ?array $body)
    {
        $url = $this->cacheBaseUrl . '/rest/v1/' . rawurlencode($table);
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        $headers = [
            'apikey: ' . $this->cacheApiKey,
            'Authorization: Bearer ' . $this->cacheApiKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return null;
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return null;
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
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

    private function formatPercent($ratio): string
    {
        if ($ratio === null) {
            return 'n/a';
        }
        return number_format((float)$ratio * 100, 1) . '%';
    }

    private function formatMoney($amount): string
    {
        return 'PHP ' . number_format((float)$amount, 0);
    }
}
