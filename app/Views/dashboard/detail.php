<!DOCTYPE html>
<html>
<head>
    <?php
    $detailCssFile = __DIR__ . '/../../../assets/css/detail.css';
    $detailJsFile = __DIR__ . '/../../../assets/js/franchise-health.js';
    $detailCssVersion = is_file($detailCssFile) ? (string)filemtime($detailCssFile) : '1';
    $detailJsVersion = is_file($detailJsFile) ? (string)filemtime($detailJsFile) : '1';
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="./assets/css/detail.css?v=<?= htmlspecialchars($detailCssVersion, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="detail-page-body">

<div class="detail-page">
    <?php
    $historyMonthlyUrl = 'history.php?period=monthly';
    $historyYearlyUrl = 'history.php?period=yearly';
    if (trim((string)$branchName) !== '') {
        $encodedBranch = rawurlencode((string)$branchName);
        $historyMonthlyUrl .= '&branch=' . $encodedBranch;
        $historyYearlyUrl .= '&branch=' . $encodedBranch;
    }
    ?>

    <!-- Left Side -->
    <div class="detail-info">
        <div class="header-details">
            <div class="detail-nav-actions">
                <a href="index.php" class="btn-details">&larr; Back to Overview</a>
                <a href="<?= htmlspecialchars($historyMonthlyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-details btn-details-alt">Monthly History</a>
                <a href="<?= htmlspecialchars($historyYearlyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-details btn-details-alt">Yearly History</a>
            </div>

            <h1 id="branchTitle" class="detail-branch-title">
                Loading...
            </h1>

            <p id="branchSubtitle" class="subtitle"></p>

        </div>

        <div class="container-details">

            <!-- Health Score Card -->
            <div id="healthCard" class="health-card">
                <div class="score" id="overallScore">0/100</div>
                <div class="progress">
                    <div class="progress-bar" id="progressBar" style="width:0%"></div>
                </div>
            </div>

            <!-- Score Breakdown -->
            <h3>Score Breakdown</h3>
            <div class="score-table-wrap">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>Factor</th>
                            <th>Raw Basis</th>
                            <th>Score</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody id="factorTableBody">
                        <tr>
                            <td colspan="4" style="text-align:center;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- Right Side -->
    <div class="aside-right-details">
        <h3 style="text-align:center;">Interpretation</h3>
        <div id="interpretationPanel" class="ai-detail-panel">
            <p class="ai-loading">Loading interpretation...</p>
        </div>
        <p class="score-note">
            Final franchise analytics score is calculated using a weighted average of all factors.
        </p>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    const selectedBranch = <?= json_encode((string)$branchName, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="assets/js/franchise-health.js?v=<?= htmlspecialchars($detailJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
