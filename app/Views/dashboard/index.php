<?php
$cssFile = __DIR__ . '/../../../assets/css/franchise-health.css';
$jsFile = __DIR__ . '/../../../assets/js/franchise-health.js';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
$jsVersion = is_file($jsFile) ? (string)filemtime($jsFile) : '1';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Franchise Analytics Dashboard</title>

    <link rel="stylesheet" href="./assets/css/franchise-health.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="dashboard-page">
<div class="top-controls">
    <h1 class="page-title">
        Franchise <span style="color:#f6bd38">Analytics</span>
        <span style="font-size: 13px; color: white;">Sample data not the real data</span>
    </h1>
    <div class="filter-bar">
        <div class="filter-left">
            <label for="statusFilter">Status:</label>
            <select id="statusFilter">
                <option value="all">All</option>
                <option value="excellent">Excellent</option>
                <option value="good">Good</option>
                <option value="warning">Warning</option>
                <option value="critical">Critical</option>
            </select>
        </div>
        <div class="filter-right">
            <input type="text" id="searchBranch" placeholder="Search branch name..." autocomplete="off">
        </div>
        <div class="view-switch" role="group" aria-label="Dashboard view mode">
            <button type="button" id="viewCardsBtn" class="view-switch-btn is-active" data-view="cards">Cards</button>
            <button type="button" id="viewCompactBtn" class="view-switch-btn" data-view="compact">Compact</button>
        </div>
        <a href="history.php?period=monthly" class="history-nav-link" aria-label="Monthly History" title="Monthly History">
            <span class="nav-icon" aria-hidden="true">Monthly</span>
            <span class="nav-label">Monthly History</span>
        </a>
        <a href="history.php?period=yearly" class="history-nav-link" aria-label="Yearly History" title="Yearly History">
            <span class="nav-icon" aria-hidden="true">Yearly</span>
            <span class="nav-label">Yearly History</span>
        </a>
    </div>
</div>
<div class="container">
    <div class="wrapper-content">
        <div class="card-container" id="dashboardCardsPanel">
            <!-- Cards will be loaded via AJAX -->
            <div class="cards" id="branchCards"></div>
        </div>
        <div class="aside-right" id="dashboardInsights">
            <h1>AI Insights</h1>
            <div class="aside-right-insight">
                <p class="ai-loading">
                    Loading AI insights...
                </p>
            </div>
        </div>
    </div>
</div>
<button
    type="button"
    id="jumpInsightsBtn"
    class="mobile-insights-jump"
    aria-controls="dashboardInsights"
    aria-label="Jump to AI insights"
>
    AI Insights
</button>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Module JS -->
<script src="assets/js/franchise-health.js?v=<?= htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>

</body>
</html>
