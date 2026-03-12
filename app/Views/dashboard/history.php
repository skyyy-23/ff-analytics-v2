<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="./assets/css/history.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<?php $isBranchScope = trim((string)$historyBranchName) !== ''; ?>
<?php $normalizedHistoryPeriod = (isset($historyPeriod) && trim((string)$historyPeriod) === 'yearly') ? 'yearly' : 'monthly'; ?>
<?php $historyPeriodTitle = $normalizedHistoryPeriod === 'yearly' ? 'Yearly' : 'Monthly'; ?>
<body class="history-page-body<?= $isBranchScope ? ' history-page-body-branch-scope' : '' ?>">
<div class="history-page">
    <header class="history-header">
        <a href="<?= htmlspecialchars((string)$historyBackHref, ENT_QUOTES, 'UTF-8') ?>" class="history-back-link">&larr; <?= htmlspecialchars((string)$historyBackLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <h1><?= htmlspecialchars((string)$historyHeading, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars((string)$historySubtitle, ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <section class="history-controls">
        <label for="historyPeriodSelect">View</label>
        <select id="historyPeriodSelect">
            <option value="monthly"<?= $normalizedHistoryPeriod === 'monthly' ? ' selected' : '' ?>>Monthly</option>
            <option value="yearly"<?= $normalizedHistoryPeriod === 'yearly' ? ' selected' : '' ?>>Yearly</option>
        </select>
        <label id="historyWindowLabel" for="historyMonthSelect">Reporting <?= $normalizedHistoryPeriod === 'yearly' ? 'Year' : 'Month' ?></label>
        <select id="historyMonthSelect">
            <option value="">Loading <?= strtolower((string)$historyPeriodTitle) ?> periods...</option>
        </select>
    </section>

    <section id="historySummaryCards" class="history-summary-cards">
        <article class="history-summary-card history-summary-average">
            <h2>Average Score</h2>
            <p class="history-summary-value" id="historyAverageScore">-</p>
        </article>
        <article class="history-summary-card history-summary-top">
            <h2>Top Branch</h2>
            <p class="history-summary-value" id="historyTopBranch">-</p>
        </article>
        <article class="history-summary-card history-summary-bottom">
            <h2>Bottom Branch</h2>
            <p class="history-summary-value" id="historyBottomBranch">-</p>
        </article>
        <article class="history-summary-card history-summary-risk">
            <h2>At-Risk Branches</h2>
            <p class="history-summary-value" id="historyRiskCount">-</p>
        </article>
    </section>

    <section id="historyYearlyGraphPanel" class="history-graph-panel<?= $normalizedHistoryPeriod === 'yearly' ? '' : ' is-hidden' ?>">
        <div class="history-graph-header">
            <div class="history-graph-headline">
                <h2 id="historyYearlyGraphTitle">Yearly Trend Graph</h2>
                <p id="historyYearlyGraphMeta">Year-over-year trend view.</p>
            </div>
            <div id="historyYearlyChartControls" class="history-graph-controls">
                <label for="historyYearlyChartType">Chart</label>
                <select id="historyYearlyChartType">
                    <option value="trend">Trend</option>
                    <option value="status_mix">Status Mix</option>
                    <option value="ranking">Ranking</option>
                </select>
            </div>
        </div>
        <div id="historyYearlyGraphCanvasWrap" class="history-graph-canvas-wrap">
            <canvas id="historyYearlyTrendCanvas" aria-label="Yearly trend graph"></canvas>
        </div>
        <p id="historyYearlyGraphEmpty" class="history-ai-empty history-graph-empty" style="display:none;"></p>
    </section>

    <section class="history-narrative-panel">
        <div class="history-narrative-header">
            <h2 id="historyNarrativeTitle"><?= htmlspecialchars($historyPeriodTitle, ENT_QUOTES, 'UTF-8') ?> Narrative</h2>
            <p id="historyNarrativeMeta">Waiting for <?= strtolower((string)$historyPeriodTitle) === 'yearly' ? 'year' : 'month' ?> selection.</p>
        </div>
        <div id="historyNarrativeBody" class="history-narrative-body">
            <p class="history-ai-loading">Loading <?= strtolower((string)$historyPeriodTitle) ?> narrative...</p>
        </div>
    </section>

    <section class="history-table-panel">
        <div class="history-table-header">
            <h2 id="historyMonthLabel"><?= htmlspecialchars($historyPeriodTitle, ENT_QUOTES, 'UTF-8') ?> Scores</h2>
            <p id="historyBranchCount">0 branches</p>
        </div>
        <div class="history-table-wrap">
            <table class="history-table">
                <thead id="historyTableHead">
                <tr id="historyTableHeadRow">
                    <th>Rank</th>
                    <th>Branch</th>
                    <th>Score</th>
                    <th>Status</th>
                    <th>Remark</th>
                </tr>
                </thead>
                <tbody id="historyTableBody">
                <tr>
                    <td colspan="5" class="history-loading-cell">Loading <?= strtolower((string)$historyPeriodTitle) ?> comparison...</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
    const historyBranch = <?= json_encode((string)$historyBranchName, JSON_UNESCAPED_UNICODE) ?>;
    const historyPeriod = <?= json_encode((string)$normalizedHistoryPeriod, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/franchise-health.js"></script>
</body>
</html>
