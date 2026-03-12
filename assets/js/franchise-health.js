$(document).ready(function () {
    var HISTORY_LIMIT = 24;
    var HISTORY_DUPLICATE_WINDOW_MS = 5 * 60 * 1000;
    var selectedHistoryId = "";
    var monthlyNarrativeCache = {};
    var yearlyNarrativeCache = {};
    var yearlyTrendChart = null;
    var yearlyGraphState = {
        years: [],
        selectedYearKey: "",
        isBranchScope: false
    };

    function normalizeLines(lines) {
        if (!Array.isArray(lines)) {
            return [];
        }

        return lines
            .filter(function (line) {
                return typeof line === "string";
            })
            .map(function (line) {
                return line.trim();
            })
            .filter(function (line) {
                return line !== "";
            });
    }

    function splitSentences(text) {
        if (typeof text !== "string") {
            return [];
        }

        var clean = text.replace(/\s+/g, " ").trim();
        if (!clean) {
            return [];
        }

        var parts = [];
        var start = 0;

        for (var i = 0; i < clean.length; i++) {
            var char = clean.charAt(i);
            if (char !== "." && char !== "!" && char !== "?") {
                continue;
            }

            var prev = i > 0 ? clean.charAt(i - 1) : "";
            var next = i + 1 < clean.length ? clean.charAt(i + 1) : "";

            // Keep decimal values like "5.0%" in one sentence.
            if (char === "." && /\d/.test(prev) && /\d/.test(next)) {
                continue;
            }

            var sentence = clean.slice(start, i + 1).trim();
            if (sentence !== "") {
                parts.push(sentence);
            }

            var nextStart = i + 1;
            while (nextStart < clean.length && /\s/.test(clean.charAt(nextStart))) {
                nextStart++;
            }

            start = nextStart;
            i = nextStart - 1;
        }

        if (start < clean.length) {
            var tail = clean.slice(start).trim();
            if (tail !== "") {
                parts.push(tail);
            }
        }

        return parts;
    }

    function formatAiInsightText(value) {
        var text = String(value == null ? "" : value);
        if (text === "") {
            return "";
        }

        return text.replace(/\bPHP\s*([0-9][0-9,]*(?:\.[0-9]+)?)/gi, function (_, amountRaw) {
            var clean = String(amountRaw || "").replace(/,/g, "").trim();
            if (!/^\d+(\.\d+)?$/.test(clean)) {
                return "PHP " + String(amountRaw || "").trim();
            }

            var valueNumber = Number(clean);
            if (!Number.isFinite(valueNumber)) {
                return "PHP " + String(amountRaw || "").trim();
            }

            var decimalPart = clean.indexOf(".") >= 0 ? clean.split(".")[1] : "";
            var decimalPlaces = decimalPart.length > 0 ? Math.min(decimalPart.length, 2) : 0;
            return "PHP " + valueNumber.toLocaleString([], {
                minimumFractionDigits: decimalPlaces,
                maximumFractionDigits: decimalPlaces
            });
        });
    }

    function priorityClass(priority) {
        var value = String(priority || "").toLowerCase();
        if (value === "high") {
            return "priority-high";
        }
        if (value === "medium") {
            return "priority-medium";
        }
        if (value === "low") {
            return "priority-low";
        }
        return "priority-default";
    }

    function priorityLabel(priority) {
        var label = String(priority || "").trim();
        return label !== "" ? label.toUpperCase() : "ACTION";
    }

    function historySignature(entry) {
        return [
            String(entry.type || ""),
            String(entry.title || ""),
            String(entry.subtitle || ""),
            normalizeLines(entry.ai_lines).join("||")
        ].join("::");
    }

    function normalizeHistoryEntry(entry) {
        if (!entry || typeof entry !== "object") {
            return null;
        }

        var id = typeof entry.id === "string" ? entry.id.trim() : "";
        if (id === "") {
            return null;
        }

        var type = entry.type === "overall" ? "overall" : "branch";
        var title = typeof entry.title === "string" ? entry.title.trim() : "";
        var subtitle = typeof entry.subtitle === "string" ? entry.subtitle.trim() : "";
        var aiLines = normalizeLines(entry.ai_lines);
        var createdAt = typeof entry.created_at === "string" && entry.created_at.trim() !== ""
            ? entry.created_at
            : new Date().toISOString();

        var normalized = {
            id: id,
            type: type,
            title: title,
            subtitle: subtitle,
            ai_lines: aiLines,
            created_at: createdAt
        };
        normalized.signature = historySignature(normalized);

        return normalized;
    }

    function readComparisonHistory() {
        return [];
    }

    function writeComparisonHistory(entries) {
        return;
    }

    function formatHistoryTime(isoTime) {
        var date = new Date(isoTime);
        if (Number.isNaN(date.getTime())) {
            return "Unknown time";
        }

        return date.toLocaleString([], {
            year: "numeric",
            month: "short",
            day: "numeric",
            hour: "numeric",
            minute: "2-digit"
        });
    }

    function renderHistoryPreview(entry) {
        var preview = $("#comparisonHistoryPreview");
        if (!preview.length) {
            return;
        }

        preview.empty();

        if (!entry) {
            preview.append($("<p>").addClass("history-empty").text("No comparison selected."));
            return;
        }

        var typeLabel = entry.type === "overall" ? "Overall comparison" : "Branch comparison";
        preview.append($("<h3>").text(entry.title || typeLabel));

        var metaParts = [typeLabel, formatHistoryTime(entry.created_at)];
        if (entry.subtitle !== "") {
            metaParts.push(entry.subtitle);
        }
        preview.append($("<p>").addClass("history-preview-meta").text(metaParts.join(" | ")));

        if (!entry.ai_lines.length) {
            preview.append($("<p>").addClass("history-empty").text("No AI response saved for this comparison."));
            return;
        }

        var lines = $("<ul>").addClass("history-preview-lines");
        entry.ai_lines.forEach(function (line) {
            lines.append($("<li>").text(line));
        });
        preview.append(lines);
    }

    function renderComparisonHistory(preferredId) {
        var list = $("#comparisonHistoryList");
        if (!list.length) {
            return;
        }

        list.empty();
        var history = readComparisonHistory();

        if (!history.length) {
            list.append($("<p>").addClass("history-empty").text("No comparison history yet."));
            renderHistoryPreview(null);
            return;
        }

        var hasPreferred = false;
        if (typeof preferredId === "string" && preferredId.trim() !== "") {
            hasPreferred = history.some(function (entry) {
                return entry.id === preferredId;
            });
        }

        var activeId = hasPreferred ? preferredId : history[0].id;
        var activeEntry = history[0];

        history.forEach(function (entry) {
            var label = entry.type === "overall" ? "Overall" : "Branch";
            var button = $("<button>")
                .attr("type", "button")
                .attr("data-history-id", entry.id)
                .addClass("history-item")
                .toggleClass("is-active", entry.id === activeId)
                .append($("<span>").addClass("history-item-type").text(label))
                .append($("<span>").addClass("history-item-title").text(entry.title || "Comparison"))
                .append($("<span>").addClass("history-item-time").text(formatHistoryTime(entry.created_at)));

            list.append(button);

            if (entry.id === activeId) {
                activeEntry = entry;
            }
        });

        selectedHistoryId = activeId;
        renderHistoryPreview(activeEntry);
    }

    function saveComparisonHistory(entry) {
        var normalized = normalizeHistoryEntry({
            id: Date.now().toString() + "-" + Math.floor(Math.random() * 1000000).toString(),
            type: entry && entry.type === "overall" ? "overall" : "branch",
            title: entry && typeof entry.title === "string" ? entry.title : "",
            subtitle: entry && typeof entry.subtitle === "string" ? entry.subtitle : "",
            ai_lines: entry && Array.isArray(entry.ai_lines) ? entry.ai_lines : [],
            created_at: new Date().toISOString()
        });

        if (!normalized) {
            return;
        }

        var history = readComparisonHistory();
        if (history.length) {
            var latest = history[0];
            var latestTime = new Date(latest.created_at).getTime();
            if (
                latest.signature === normalized.signature &&
                !Number.isNaN(latestTime) &&
                (Date.now() - latestTime) < HISTORY_DUPLICATE_WINDOW_MS
            ) {
                return;
            }
        }

        history.unshift(normalized);
        if (history.length > HISTORY_LIMIT) {
            history = history.slice(0, HISTORY_LIMIT);
        }

        writeComparisonHistory(history);
        renderComparisonHistory(normalized.id);
    }

    function saveBranchComparisonHistory(branchName, metadata, aiLines) {
        var cleanBranch = String(branchName || "").trim();
        if (cleanBranch === "") {
            return;
        }

        var score = metadata && metadata.overall_score != null ? String(metadata.overall_score).trim() : "";
        var status = metadata && typeof metadata.status_text === "string" ? metadata.status_text.trim() : "";
        var subtitleParts = [];

        if (score !== "") {
            subtitleParts.push("Score " + score + "/100");
        }
        if (status !== "") {
            subtitleParts.push(status);
        }

        saveComparisonHistory({
            type: "branch",
            title: "Branch: " + cleanBranch,
            subtitle: subtitleParts.join(" | "),
            ai_lines: normalizeLines(aiLines)
        });
    }

    function formatRecommendationLine(rec, index) {
        var label = "Action " + (index + 1) + ": ";
        if (!rec || typeof rec !== "object") {
            var value = String(rec || "").trim();
            return value === "" ? "" : label + value;
        }

        var action = typeof rec.action === "string" ? rec.action.trim() : "";
        var reason = typeof rec.reason === "string" ? rec.reason.trim() : "";
        var priority = typeof rec.priority === "string" ? rec.priority.trim().toUpperCase() : "";

        if (action === "" && reason === "") {
            return "";
        }

        var parts = [];
        if (action !== "") {
            parts.push(action);
        }
        if (reason !== "") {
            parts.push(reason);
        }

        var text = label + parts.join(" - ");
        if (priority !== "") {
            text += " [" + priority + "]";
        }
        return text;
    }

    function saveOverallComparisonHistory(summaryText, recommendations) {
        var lines = [];
        var summary = typeof summaryText === "string" ? summaryText.trim() : "";
        if (summary !== "") {
            lines.push(summary);
        }

        if (Array.isArray(recommendations)) {
            recommendations.forEach(function (rec, index) {
                var line = formatRecommendationLine(rec, index);
                if (line !== "") {
                    lines.push(line);
                }
            });
        }

        if (!lines.length) {
            return;
        }

        saveComparisonHistory({
            type: "overall",
            title: "Overall Branches",
            subtitle: "Dashboard AI snapshot",
            ai_lines: lines
        });
    }

    function initComparisonHistory() {
        var historyRoot = $("#comparisonHistoryBar");
        if (!historyRoot.length) {
            return;
        }

        $("#comparisonHistoryList")
            .off("click.history")
            .on("click.history", ".history-item", function () {
                selectedHistoryId = $(this).attr("data-history-id") || "";
                renderComparisonHistory(selectedHistoryId);
            });

        $("#clearComparisonHistory")
            .off("click.history")
            .on("click.history", function () {
                writeComparisonHistory([]);
                selectedHistoryId = "";
                renderComparisonHistory("");
            });

        renderComparisonHistory(selectedHistoryId);
    }

    function statusClassFromKey(statusKey) {
        var key = String(statusKey || "").toLowerCase();
        if (key === "excellent" || key === "good" || key === "warning" || key === "critical") {
            return "status-" + key;
        }
        return "status-good";
    }

    function getHistoryBranchScope() {
        if (typeof historyBranch === "string" && historyBranch.trim() !== "") {
            return historyBranch.trim();
        }

        var queryBranch = (new URLSearchParams(window.location.search).get("branch") || "").trim();
        return queryBranch;
    }

    function getHistoryPeriod() {
        var inlinePeriod = typeof historyPeriod === "string" ? historyPeriod.trim().toLowerCase() : "";
        if (inlinePeriod === "yearly" || inlinePeriod === "monthly") {
            return inlinePeriod;
        }

        var queryPeriod = (new URLSearchParams(window.location.search).get("period") || "").trim().toLowerCase();
        return queryPeriod === "yearly" ? "yearly" : "monthly";
    }

    function applyHistoryPeriodPresentation(period) {
        var isYearly = period === "yearly";
        var periodLabel = isYearly ? "Yearly" : "Monthly";
        var periodUnit = isYearly ? "Year" : "Month";

        var windowLabel = $("#historyWindowLabel");
        if (windowLabel.length) {
            windowLabel.text("Reporting " + periodUnit);
        }

        var narrativeTitle = $("#historyNarrativeTitle");
        if (narrativeTitle.length) {
            narrativeTitle.text(periodLabel + " Narrative");
        }

        setYearlyGraphVisibility(isYearly);
    }

    function buildHistoryPageUrl(period, branchScope) {
        var params = new URLSearchParams();
        params.set("period", period === "yearly" ? "yearly" : "monthly");
        if (branchScope !== "") {
            params.set("branch", branchScope);
        }
        return "history.php?" + params.toString();
    }

    function initHistoryPeriodSwitcher(period, branchScope) {
        var switcher = $("#historyPeriodSelect");
        if (!switcher.length) {
            return;
        }

        switcher.val(period === "yearly" ? "yearly" : "monthly");
        switcher.off("change.period").on("change.period", function () {
            var selectedPeriod = ($(this).val() || "").toString().trim().toLowerCase() === "yearly" ? "yearly" : "monthly";
            window.location.href = buildHistoryPageUrl(selectedPeriod, branchScope);
        });
    }

    function destroyYearlyTrendChart() {
        if (yearlyTrendChart && typeof yearlyTrendChart.destroy === "function") {
            yearlyTrendChart.destroy();
        }
        yearlyTrendChart = null;
    }

    function setYearlyGraphVisibility(isVisible) {
        var panel = $("#historyYearlyGraphPanel");
        if (!panel.length) {
            return;
        }

        panel.toggleClass("is-hidden", !isVisible);
        if (!isVisible) {
            destroyYearlyTrendChart();
            yearlyGraphState.years = [];
            yearlyGraphState.selectedYearKey = "";
            $("#historyYearlyGraphEmpty").hide().text("");
            $("#historyYearlyGraphCanvasWrap").show();
        }
    }

    function showYearlyGraphEmpty(message) {
        var emptyNode = $("#historyYearlyGraphEmpty");
        var canvasWrap = $("#historyYearlyGraphCanvasWrap");
        if (!emptyNode.length || !canvasWrap.length) {
            return;
        }

        destroyYearlyTrendChart();
        canvasWrap.hide();
        emptyNode.text(message || "No yearly graph data available.").show();
    }

    function getNormalizedYearlyChartType(value, isBranchScope) {
        var raw = String(value || "").trim().toLowerCase();
        var allowed = isBranchScope ? ["trend"] : ["trend", "status_mix", "ranking"];
        return allowed.indexOf(raw) >= 0 ? raw : allowed[0];
    }

    function configureYearlyChartTypeSwitcher(isBranchScope) {
        var controls = $("#historyYearlyChartControls");
        var select = $("#historyYearlyChartType");
        if (!select.length) {
            return;
        }

        select.empty()
            .append($("<option>").val("trend").text("Trend"));
        if (!isBranchScope) {
            select
                .append($("<option>").val("status_mix").text("Status Mix"))
                .append($("<option>").val("ranking").text("Ranking"));
        }

        var normalized = getNormalizedYearlyChartType(select.val(), isBranchScope);
        select.val(normalized);

        if (controls.length) {
            controls.removeClass("is-hidden");
        }

        select.off("change.yearlyChartType").on("change.yearlyChartType", function () {
            if (!Array.isArray(yearlyGraphState.years) || !yearlyGraphState.years.length) {
                return;
            }
            var nextType = getNormalizedYearlyChartType($(this).val(), yearlyGraphState.isBranchScope);
            if ($(this).val() !== nextType) {
                $(this).val(nextType);
            }
            renderYearlyTrendGraph(
                yearlyGraphState.years,
                yearlyGraphState.selectedYearKey,
                yearlyGraphState.isBranchScope
            );
        });
    }

    function setYearlyGraphState(years, selectedYearKey, isBranchScope) {
        yearlyGraphState.years = Array.isArray(years) ? years.slice() : [];
        yearlyGraphState.selectedYearKey = String(selectedYearKey || "");
        yearlyGraphState.isBranchScope = Boolean(isBranchScope);
    }

    function statusColor(statusKey, alpha) {
        var key = String(statusKey || "").trim().toLowerCase();
        var palette = {
            excellent: "34,197,94",
            good: "59,130,246",
            warning: "245,158,11",
            critical: "239,68,68"
        };
        var rgb = palette[key] || "107,114,128";
        return "rgba(" + rgb + "," + alpha + ")";
    }

    function renderYearlyTrendGraph(years, selectedYearKey, isBranchScope) {
        var canvas = $("#historyYearlyTrendCanvas");
        var emptyNode = $("#historyYearlyGraphEmpty");
        var canvasWrap = $("#historyYearlyGraphCanvasWrap");
        var titleNode = $("#historyYearlyGraphTitle");
        var metaNode = $("#historyYearlyGraphMeta");
        var chartTypeSelect = $("#historyYearlyChartType");

        if (!canvas.length || !canvasWrap.length || !emptyNode.length) {
            return;
        }

        if (typeof Chart === "undefined") {
            showYearlyGraphEmpty("Graph library is unavailable. Table and narrative are still available.");
            return;
        }

        var list = Array.isArray(years) ? years.slice() : [];
        list = list.filter(function (item) {
            return item && typeof item === "object";
        });
        if (!list.length) {
            showYearlyGraphEmpty("No yearly graph data available.");
            return;
        }

        list.sort(function (a, b) {
            return String(a.year_key || "").localeCompare(String(b.year_key || ""));
        });

        var labels = [];
        var averageScores = [];
        var riskCounts = [];
        var sampleCounts = [];
        var selectedIndex = -1;
        var selectedBranchName = "";

        list.forEach(function (year, index) {
            var yearKey = String(year && year.year_key ? year.year_key : "");
            labels.push(String(year && (year.year_label || year.year_key) ? (year.year_label || year.year_key) : "N/A"));
            averageScores.push(Number(year && year.average_score != null ? year.average_score : 0));
            riskCounts.push(Number(year && year.risk_count != null ? year.risk_count : 0));

            var sampleCount = 0;
            if (year && Array.isArray(year.branches) && year.branches[0] && year.branches[0].sample_count != null) {
                sampleCount = Number(year.branches[0].sample_count);
                if (selectedBranchName === "" && typeof year.branches[0].branch_name === "string") {
                    selectedBranchName = year.branches[0].branch_name;
                }
            }
            sampleCounts.push(sampleCount);

            if (selectedIndex < 0 && yearKey !== "" && yearKey === String(selectedYearKey || "")) {
                selectedIndex = index;
            }
        });

        if (selectedIndex < 0) {
            selectedIndex = labels.length - 1;
        }

        var selectedYear = list[selectedIndex] || list[list.length - 1] || null;
        var selectedYearLabel = selectedYear && (selectedYear.year_label || selectedYear.year_key)
            ? String(selectedYear.year_label || selectedYear.year_key)
            : "selected year";
        var chartType = getNormalizedYearlyChartType(chartTypeSelect.val(), isBranchScope);
        if (chartTypeSelect.length && chartTypeSelect.val() !== chartType) {
            chartTypeSelect.val(chartType);
        }

        canvasWrap.show();
        emptyNode.hide().text("");
        destroyYearlyTrendChart();

        var context = canvas.get(0).getContext("2d");
        var selectedPointRadius = labels.map(function (_, index) {
            return index === selectedIndex ? 5 : 3;
        });
        var selectedBarColors = labels.map(function (_, index) {
            return index === selectedIndex ? "rgba(239, 103, 40, 0.70)" : "rgba(59, 130, 246, 0.45)";
        });

        var chartConfig = null;
        if (chartType === "status_mix") {
            var excellentCounts = [];
            var goodCounts = [];
            var warningCounts = [];
            var criticalCounts = [];
            list.forEach(function (yearItem) {
                var counts = buildMonthlyStatusCountsFromMonth(yearItem);
                excellentCounts.push(Number(counts.EXCELLENT || 0));
                goodCounts.push(Number(counts.GOOD || 0));
                warningCounts.push(Number(counts.WARNING || 0));
                criticalCounts.push(Number(counts.CRITICAL || 0));
            });

            if (titleNode.length) {
                titleNode.text("Yearly Status Mix Graph");
            }
            if (metaNode.length) {
                metaNode.text("Stacked branch status distribution by year.");
            }

            chartConfig = {
                type: "bar",
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: "Excellent",
                            data: excellentCounts,
                            backgroundColor: statusColor("excellent", 0.75),
                            borderColor: statusColor("excellent", 1),
                            borderWidth: 1
                        },
                        {
                            label: "Good",
                            data: goodCounts,
                            backgroundColor: statusColor("good", 0.75),
                            borderColor: statusColor("good", 1),
                            borderWidth: 1
                        },
                        {
                            label: "Warning",
                            data: warningCounts,
                            backgroundColor: statusColor("warning", 0.75),
                            borderColor: statusColor("warning", 1),
                            borderWidth: 1
                        },
                        {
                            label: "Critical",
                            data: criticalCounts,
                            backgroundColor: statusColor("critical", 0.75),
                            borderColor: statusColor("critical", 1),
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: "bottom"
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                color: "rgba(148, 163, 184, 0.12)"
                            }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            title: {
                                display: true,
                                text: "Branch Count"
                            },
                            grid: {
                                color: "rgba(148, 163, 184, 0.20)"
                            }
                        }
                    }
                }
            };
        } else if (chartType === "ranking") {
            var selectedBranches = (selectedYear && Array.isArray(selectedYear.branches)) ? selectedYear.branches.slice() : [];
            selectedBranches = selectedBranches
                .filter(function (branch) {
                    return branch && typeof branch === "object";
                })
                .sort(function (a, b) {
                    return Number(b.overall_score || 0) - Number(a.overall_score || 0);
                })
                .slice(0, 10);

            if (!selectedBranches.length) {
                showYearlyGraphEmpty("No branch ranking data available for " + selectedYearLabel + ".");
                return;
            }

            var rankingLabels = [];
            var rankingScores = [];
            var rankingColors = [];
            var rankingSamples = [];
            selectedBranches.forEach(function (branch) {
                rankingLabels.push(String(branch.branch_name || "Unknown Branch"));
                rankingScores.push(Number(branch.overall_score != null ? branch.overall_score : 0));
                rankingColors.push(statusColor(branch.status_key, 0.72));
                rankingSamples.push(Number(branch.sample_count != null ? branch.sample_count : 0));
            });

            if (titleNode.length) {
                titleNode.text("Branch Ranking Graph");
            }
            if (metaNode.length) {
                metaNode.text("Top branches by yearly score for " + selectedYearLabel + ".");
            }

            chartConfig = {
                type: "bar",
                data: {
                    labels: rankingLabels,
                    datasets: [
                        {
                            label: "Yearly Score",
                            data: rankingScores,
                            backgroundColor: rankingColors,
                            borderColor: rankingColors.map(function (color) {
                                return color.replace(/,0\.72\)$/i, ",1)");
                            }),
                            borderWidth: 1.5,
                            borderRadius: 6,
                            maxBarThickness: 26
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: "y",
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var monthCount = rankingSamples[context.dataIndex] || 0;
                                    return "Score: " + context.parsed.x + "/100 | Months covered: " + monthCount;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            min: 0,
                            max: 100,
                            title: {
                                display: true,
                                text: "Score"
                            },
                            ticks: {
                                stepSize: 10
                            },
                            grid: {
                                color: "rgba(148, 163, 184, 0.20)"
                            }
                        },
                        y: {
                            ticks: {
                                autoSkip: false
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            };
        } else {
            if (titleNode.length) {
                titleNode.text(isBranchScope ? "Yearly Branch Trend Graph" : "Yearly Business Trend Graph");
            }
            if (metaNode.length) {
                if (isBranchScope) {
                    var branchMetaName = selectedBranchName !== "" ? selectedBranchName : "Selected branch";
                    metaNode.text(branchMetaName + ": score trend (line) and monthly coverage (bars).");
                } else {
                    metaNode.text("Average score trend (line) and at-risk branches (bars) by year.");
                }
            }

            var trendDatasets;
            if (isBranchScope) {
                trendDatasets = [
                    {
                        type: "line",
                        label: "Yearly Score",
                        data: averageScores,
                        yAxisID: "y",
                        borderColor: "#2563eb",
                        backgroundColor: "rgba(37, 99, 235, 0.18)",
                        tension: 0.3,
                        pointRadius: selectedPointRadius,
                        pointHoverRadius: 6,
                        borderWidth: 3,
                        fill: false
                    },
                    {
                        type: "bar",
                        label: "Months Covered",
                        data: sampleCounts,
                        yAxisID: "y1",
                        backgroundColor: selectedBarColors,
                        borderColor: "#ef6728",
                        borderWidth: 1,
                        borderRadius: 6,
                        maxBarThickness: 36
                    }
                ];
            } else {
                trendDatasets = [
                    {
                        type: "line",
                        label: "Average Score",
                        data: averageScores,
                        yAxisID: "y",
                        borderColor: "#2563eb",
                        backgroundColor: "rgba(37, 99, 235, 0.18)",
                        tension: 0.3,
                        pointRadius: selectedPointRadius,
                        pointHoverRadius: 6,
                        borderWidth: 3,
                        fill: false
                    },
                    {
                        type: "bar",
                        label: "At-Risk Branches",
                        data: riskCounts,
                        yAxisID: "y1",
                        backgroundColor: selectedBarColors,
                        borderColor: "#ef6728",
                        borderWidth: 1,
                        borderRadius: 6,
                        maxBarThickness: 36
                    }
                ];
            }

            chartConfig = {
                type: "bar",
                data: {
                    labels: labels,
                    datasets: trendDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: "index",
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: "bottom",
                            labels: {
                                boxWidth: 14,
                                boxHeight: 14,
                                usePointStyle: false
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    if (context.dataset.yAxisID === "y") {
                                        return context.dataset.label + ": " + context.parsed.y + "/100";
                                    }
                                    return context.dataset.label + ": " + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            position: "left",
                            min: 0,
                            max: 100,
                            title: {
                                display: true,
                                text: "Score"
                            },
                            ticks: {
                                stepSize: 10
                            },
                            grid: {
                                color: "rgba(148, 163, 184, 0.20)"
                            }
                        },
                        y1: {
                            position: "right",
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: isBranchScope ? "Months" : "Branches"
                            },
                            ticks: {
                                precision: 0
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            };
        }

        yearlyTrendChart = new Chart(context, chartConfig);
    }

    function getHistoryTableColspan(isBranchScope) {
        return isBranchScope ? 4 : 5;
    }

    function setMonthlyHistoryTableMode(isBranchScope) {
        var headRow = $("#historyTableHeadRow");
        if (!headRow.length) {
            return;
        }

        headRow.empty();
        if (isBranchScope) {
            headRow.append($("<th>").text("Factor"));
            headRow.append($("<th>").text("Raw Basis"));
            headRow.append($("<th>").text("Score"));
            headRow.append($("<th>").text("Weight"));
            return;
        }

        headRow.append($("<th>").text("Rank"));
        headRow.append($("<th>").text("Branch"));
        headRow.append($("<th>").text("Score"));
        headRow.append($("<th>").text("Status"));
        headRow.append($("<th>").text("Remark"));
    }

    function setMonthlyHistorySummary(month, isBranchScope) {
        var monthLabel = String(month.month_label || month.month_key || "Unknown Month");
        $("#historyAverageScore").text(month.average_score + "/100");
        $("#historyTopBranch").text(
            month.top_branch && month.top_branch.branch_name
                ? month.top_branch.branch_name + " (" + month.top_branch.overall_score + ")"
                : "N/A"
        );
        $("#historyBottomBranch").text(
            month.bottom_branch && month.bottom_branch.branch_name
                ? month.bottom_branch.branch_name + " (" + month.bottom_branch.overall_score + ")"
                : "N/A"
        );
        $("#historyRiskCount").text(String(month.risk_count || 0));
        if (isBranchScope) {
            var selectedBranchName = month && Array.isArray(month.branches) && month.branches[0] && month.branches[0].branch_name
                ? month.branches[0].branch_name
                : "Selected branch";
            $("#historyMonthLabel").text("Monthly Score Breakdown - " + monthLabel);
            $("#historyBranchCount").text(selectedBranchName);
            return;
        }

        $("#historyMonthLabel").text("Monthly Scores - " + monthLabel);
        $("#historyBranchCount").text(String(month.branch_count || 0) + " branches");
    }

    function setYearlyHistorySummary(year, isBranchScope) {
        var yearLabel = String(year.year_label || year.year_key || "Unknown Year");
        $("#historyAverageScore").text(String(year.average_score != null ? year.average_score : 0) + "/100");
        $("#historyTopBranch").text(
            year.top_branch && year.top_branch.branch_name
                ? year.top_branch.branch_name + " (" + year.top_branch.overall_score + ")"
                : "N/A"
        );
        $("#historyBottomBranch").text(
            year.bottom_branch && year.bottom_branch.branch_name
                ? year.bottom_branch.branch_name + " (" + year.bottom_branch.overall_score + ")"
                : "N/A"
        );
        $("#historyRiskCount").text(String(year.risk_count || 0));

        if (isBranchScope) {
            var selectedBranchName = year && Array.isArray(year.branches) && year.branches[0] && year.branches[0].branch_name
                ? year.branches[0].branch_name
                : "Selected branch";
            $("#historyMonthLabel").text("Yearly Score Breakdown - " + yearLabel);
            $("#historyBranchCount").text(selectedBranchName);
            return;
        }

        $("#historyMonthLabel").text("Yearly Scores - " + yearLabel);
        $("#historyBranchCount").text(String(year.branch_count || 0) + " branches");
    }

    function renderBranchMonthlyBreakdown(month) {
        var tableBody = $("#historyTableBody");
        tableBody.empty();

        var branches = month && Array.isArray(month.branches) ? month.branches : [];
        var selectedBranch = branches.length ? branches[0] : null;
        if (!selectedBranch) {
            tableBody.append(
                $("<tr>").append(
                    $("<td>")
                        .attr("colspan", String(getHistoryTableColspan(true)))
                        .addClass("history-empty-cell")
                        .text("No branch data found for this month.")
                )
            );
            return;
        }

        var overallLine = String(selectedBranch.branch_name || "Selected branch") +
            " overall score: " + String(selectedBranch.overall_score != null ? selectedBranch.overall_score : "-") +
            "/100 (" + String(selectedBranch.status || "N/A") + ")";
        tableBody.append(
            $("<tr>").append(
                $("<td>")
                    .attr("colspan", String(getHistoryTableColspan(true)))
                    .addClass("history-breakdown-summary")
                    .text(overallLine)
            )
        );

        var factors = Array.isArray(selectedBranch.factors) ? selectedBranch.factors : [];
        if (!factors.length) {
            tableBody.append(
                $("<tr>").append(
                    $("<td>")
                        .attr("colspan", String(getHistoryTableColspan(true)))
                        .addClass("history-empty-cell")
                        .text("No score breakdown is available for this month.")
                )
            );
            return;
        }

        factors.forEach(function (factor) {
            var row = $("<tr>");
            row.append($("<td>").text(String(factor && factor.name ? factor.name : "Unknown Factor")));
            row.append($("<td>").text(String(factor && factor.raw_basis ? factor.raw_basis : "N/A")));
            row.append($("<td>").addClass("history-score").text(String(factor && factor.score != null ? factor.score : "-")));
            row.append($("<td>").text(String(factor && factor.weight != null ? factor.weight : "-") + "%"));
            tableBody.append(row);
        });
    }

    function renderMonthlyHistoryRows(month, isBranchScope) {
        if (isBranchScope) {
            renderBranchMonthlyBreakdown(month);
            return;
        }

        var tableBody = $("#historyTableBody");
        tableBody.empty();

        var branches = month && Array.isArray(month.branches) ? month.branches : [];
        if (!branches.length) {
            tableBody.append(
                $("<tr>").append(
                    $("<td>")
                        .attr("colspan", String(getHistoryTableColspan(false)))
                        .addClass("history-empty-cell")
                        .text("No branch comparison data for this month.")
                )
            );
            return;
        }

        branches.forEach(function (branch, index) {
            var statusKey = String(branch && branch.status_key ? branch.status_key : "").toLowerCase();
            var statusText = branch && typeof branch.status === "string" ? branch.status : "N/A";
            var remark = branch && typeof branch.status_text === "string" ? branch.status_text : "";
            var row = $("<tr>");

            row.append($("<td>").text(String(index + 1)));
            row.append($("<td>").text(branch && branch.branch_name ? branch.branch_name : "Unknown branch"));
            row.append($("<td>").addClass("history-score").text(String(branch && branch.overall_score != null ? branch.overall_score : "-") + "/100"));
            row.append(
                $("<td>").append(
                    $("<span>")
                        .addClass("history-status-pill")
                        .addClass(statusClassFromKey(statusKey))
                        .text(statusText)
                )
            );
            row.append($("<td>").text(remark));

            tableBody.append(row);
        });
    }

    function buildMonthlyStatusCountsFromMonth(month) {
        var counts = {
            EXCELLENT: 0,
            GOOD: 0,
            WARNING: 0,
            CRITICAL: 0
        };

        var branches = month && Array.isArray(month.branches) ? month.branches : [];
        branches.forEach(function (branch) {
            var status = String(branch && branch.status ? branch.status : "").toUpperCase();
            if (Object.prototype.hasOwnProperty.call(counts, status)) {
                counts[status] += 1;
            }
        });

        return counts;
    }

    function buildMonthlyNarrativeFallbackFromMonth(month) {
        if (!month || typeof month !== "object") {
            return [];
        }

        var monthLabel = typeof month.month_label === "string" && month.month_label.trim() !== ""
            ? month.month_label.trim()
            : String(month.month_key || "Selected month");
        var branchCount = month.branch_count != null ? month.branch_count : 0;
        var averageScore = month.average_score != null ? month.average_score : 0;
        var riskCount = month.risk_count != null ? month.risk_count : 0;
        var counts = buildMonthlyStatusCountsFromMonth(month);
        var topName = month.top_branch && month.top_branch.branch_name ? month.top_branch.branch_name : "N/A";
        var topScore = month.top_branch && month.top_branch.overall_score != null ? month.top_branch.overall_score : 0;
        var bottomName = month.bottom_branch && month.bottom_branch.branch_name ? month.bottom_branch.branch_name : "N/A";
        var bottomScore = month.bottom_branch && month.bottom_branch.overall_score != null ? month.bottom_branch.overall_score : 0;
        var singleBranchScope = Number(branchCount) === 1 && topName !== "N/A";

        var lines = [];
        if (singleBranchScope) {
            lines.push(
                monthLabel + " snapshot: " + topName + " scored " + topScore + "/100 for this branch-only monthly view."
            );
            lines.push(
                "This month includes 1 branch with " + riskCount + " risk flag(s)."
            );
        } else {
            lines.push(
                monthLabel + " snapshot: average health score is " + averageScore + " across " + branchCount +
                " branches (" + counts.EXCELLENT + " excellent, " + counts.GOOD + " good, " +
                counts.WARNING + " warning, " + counts.CRITICAL + " critical)."
            );
            lines.push(
                "Top performer is " + topName + " at " + topScore + "/100, while " + bottomName +
                " is lowest at " + bottomScore + "/100."
            );
        }

        if (riskCount > 0) {
            lines.push(riskCount + " branches are at risk this month (warning or critical).");
            lines.push("Main action: prioritize operational coaching for at-risk branches this month.");
        } else {
            lines.push("No branch is in warning or critical status for this month.");
            lines.push("Main action: maintain controls and monitor weekly score drift.");
        }

        return lines;
    }

    function buildYearlyNarrativeFallbackFromYear(year, previousYear, isBranchScope) {
        if (!year || typeof year !== "object") {
            return [];
        }

        var yearLabel = typeof year.year_label === "string" && year.year_label.trim() !== ""
            ? year.year_label.trim()
            : String(year.year_key || "Selected year");
        var branchCount = year.branch_count != null ? year.branch_count : 0;
        var averageScore = year.average_score != null ? year.average_score : 0;
        var riskCount = year.risk_count != null ? year.risk_count : 0;
        var counts = buildMonthlyStatusCountsFromMonth(year);
        var topName = year.top_branch && year.top_branch.branch_name ? year.top_branch.branch_name : "N/A";
        var topScore = year.top_branch && year.top_branch.overall_score != null ? year.top_branch.overall_score : 0;
        var bottomName = year.bottom_branch && year.bottom_branch.branch_name ? year.bottom_branch.branch_name : "N/A";
        var bottomScore = year.bottom_branch && year.bottom_branch.overall_score != null ? year.bottom_branch.overall_score : 0;
        var lines = [];

        if (isBranchScope || Number(branchCount) === 1) {
            lines.push(
                yearLabel + " snapshot: " + topName + " averaged " + topScore + "/100 in this yearly branch view."
            );
            var sampleCount = year.top_branch && year.top_branch.sample_count != null ? year.top_branch.sample_count : 0;
            lines.push(
                "This yearly view summarizes " + sampleCount + " month(s) and has " + riskCount + " risk flag(s)."
            );
        } else {
            lines.push(
                yearLabel + " summary: average health score is " + averageScore + " across " + branchCount +
                " branches (" + counts.EXCELLENT + " excellent, " + counts.GOOD + " good, " +
                counts.WARNING + " warning, " + counts.CRITICAL + " critical)."
            );
            lines.push(
                "Top performer is " + topName + " at " + topScore + "/100, while " + bottomName +
                " is lowest at " + bottomScore + "/100."
            );
        }

        if (previousYear && typeof previousYear === "object") {
            var previousLabel = previousYear.year_label || previousYear.year_key || "previous year";
            var previousAverage = previousYear.average_score != null ? previousYear.average_score : 0;
            var previousRiskCount = previousYear.risk_count != null ? previousYear.risk_count : 0;
            var averageDelta = Number(averageScore) - Number(previousAverage);
            var riskDelta = Number(riskCount) - Number(previousRiskCount);
            lines.push(
                "Year-over-year vs " + previousLabel + ": average score " + (averageDelta >= 0 ? "+" : "") + averageDelta +
                " points and at-risk branches " + (riskDelta >= 0 ? "+" : "") + riskDelta + "."
            );
        }

        if (riskCount > 0) {
            lines.push(riskCount + " branches are at risk this year (warning or critical).");
            lines.push("Main action: prioritize annual recovery plans for at-risk branches.");
        } else {
            lines.push("No branch is in warning or critical status for this year.");
            lines.push("Main action: sustain controls and monitor quarter-by-quarter score drift.");
        }

        return lines;
    }

    function renderMonthlyNarrative(lines, sourceLabel, metaText) {
        var body = $("#historyNarrativeBody");
        var meta = $("#historyNarrativeMeta");
        if (!body.length || !meta.length) {
            return;
        }

        body.empty();
        var cleanLines = normalizeLines(lines);

        if (typeof metaText === "string" && metaText.trim() !== "") {
            meta.text(metaText.trim());
        } else if (typeof sourceLabel === "string" && sourceLabel.trim() !== "") {
            meta.text("Source: " + sourceLabel.trim());
        } else {
            meta.text("Monthly narrative");
        }

        if (!cleanLines.length) {
            body.append($("<p>").addClass("history-ai-empty").text("No monthly narrative available right now."));
            return;
        }

        var overview = cleanLines[0];
        var points = [];
        var actions = [];
        var actionPattern = /^(?:[-*]\s*)?(Main action|Priority action|Recommended action)\s*:/i;

        function extractActionText(line) {
            return String(line || "").replace(actionPattern, "").trim();
        }

        cleanLines.slice(1).forEach(function (line) {
            if (actionPattern.test(line)) {
                actions.push(extractActionText(line));
            } else {
                points.push(line);
            }
        });

        var overviewSentences = splitSentences(overview);
        if (overviewSentences.length) {
            overview = overviewSentences[0];
            if (overviewSentences.length > 1) {
                points = overviewSentences.slice(1).concat(points);
            }
        }

        points = points.reduce(function (acc, line) {
            var sentences = splitSentences(String(line || ""));
            if (!sentences.length) {
                var clean = String(line || "").trim();
                if (clean !== "") {
                    if (actionPattern.test(clean)) {
                        actions.push(extractActionText(clean));
                    } else {
                        acc.push(clean);
                    }
                }
                return acc;
            }

            sentences.forEach(function (sentence) {
                var cleanSentence = sentence.trim();
                if (cleanSentence === "") {
                    return;
                }
                if (actionPattern.test(cleanSentence)) {
                    actions.push(extractActionText(cleanSentence));
                } else {
                    acc.push(cleanSentence);
                }
            });
            return acc;
        }, []);

        if (overview) {
            body.append(
                $("<section>").addClass("history-ai-overview").append(
                    $("<h3>").text("Summary"),
                    $("<p>").text(overview)
                )
            );
        }

        if (points.length) {
            var pointsList = $("<ul>");
            points.forEach(function (point) {
                pointsList.append($("<li>").text(point));
            });

            body.append(
                $("<section>").addClass("history-ai-points").append(
                    $("<h3>").text("Key Points"),
                    pointsList
                )
            );
        }

        if (actions.length) {
            body.append(
                $("<section>").addClass("history-ai-action").append(
                    $("<h3>").text("Main Action"),
                    $("<p>").text(actions[0])
                )
            );
        }
    }

    function loadMonthlyNarrative(month, branchScope) {
        var body = $("#historyNarrativeBody");
        if (!body.length) {
            return;
        }

        if (!month || typeof month !== "object") {
            renderMonthlyNarrative([], "fallback", "No month selected.");
            return;
        }

        var monthKey = typeof month.month_key === "string" ? month.month_key : "";
        if (monthKey === "") {
            renderMonthlyNarrative([], "fallback", "Invalid selected month.");
            return;
        }

        var normalizedBranchScope = String(branchScope || "").trim();
        var cacheKey = monthKey + "::" + normalizedBranchScope.toLowerCase();

        if (monthlyNarrativeCache[cacheKey]) {
            var cached = monthlyNarrativeCache[cacheKey];
            renderMonthlyNarrative(cached.lines, cached.source, cached.meta);
            return;
        }

        body.empty().append($("<p>").addClass("history-ai-loading").text("Loading AI monthly narrative..."));
        $("#historyNarrativeMeta").text("Generating narrative...");

        var requestData = { month: monthKey, t: Date.now() };
        if (normalizedBranchScope !== "") {
            requestData.branch = normalizedBranchScope;
        }

        $.getJSON("ajax/get_ai_monthly_narrative.php", requestData)
            .done(function (data) {
                var lines = data && Array.isArray(data.narrative) ? normalizeLines(data.narrative) : [];
                var source = data && typeof data.source === "string" && data.source.trim() !== ""
                    ? data.source.trim().toUpperCase()
                    : "FALLBACK";
                var error = data && typeof data.error === "string" ? data.error.trim() : "";
                var aiStatus = data && typeof data.ai_status === "string" ? data.ai_status.trim() : "";
                var aiError = data && typeof data.ai_error === "string" ? data.ai_error.trim() : "";

                if (!lines.length) {
                    lines = buildMonthlyNarrativeFallbackFromMonth(month);
                    source = "FALLBACK";
                }

                var metaParts = [];
                if (error !== "") {
                    metaParts.push(error);
                } else {
                    metaParts.push("Source: " + source);
                }
                if (aiStatus !== "") {
                    metaParts.push("Status: " + aiStatus.replace(/_/g, " "));
                }
                if (aiError !== "") {
                    metaParts.push("Reason: " + aiError);
                }
                var metaMessage = metaParts.join(" | ");
                monthlyNarrativeCache[cacheKey] = {
                    lines: lines,
                    source: source,
                    meta: metaMessage
                };
                renderMonthlyNarrative(lines, source, metaMessage);
            })
            .fail(function () {
                var fallbackLines = buildMonthlyNarrativeFallbackFromMonth(month);
                var metaMessage = "AI request failed. Showing formula-based fallback narrative.";
                monthlyNarrativeCache[cacheKey] = {
                    lines: fallbackLines,
                    source: "FALLBACK",
                    meta: metaMessage
                };
                renderMonthlyNarrative(fallbackLines, "FALLBACK", metaMessage);
            });
    }

    function renderYearlyNarrative(lines, sourceLabel, errorMessage) {
        var body = $("#historyNarrativeBody");
        var meta = $("#historyNarrativeMeta");
        if (!body.length || !meta.length) {
            return;
        }

        body.empty();
        var cleanLines = normalizeLines(lines);

        if (typeof errorMessage === "string" && errorMessage.trim() !== "") {
            meta.text(errorMessage.trim());
        } else if (typeof sourceLabel === "string" && sourceLabel.trim() !== "") {
            meta.text("Source: " + sourceLabel.trim());
        } else {
            meta.text("Yearly narrative");
        }

        if (!cleanLines.length) {
            body.append($("<p>").addClass("history-ai-empty").text("No yearly narrative available right now."));
            return;
        }

        var overview = cleanLines[0];
        var points = [];
        var actions = [];

        cleanLines.slice(1).forEach(function (line) {
            if (/^Main action\s*:/i.test(line)) {
                actions.push(line.replace(/^Main action\s*:\s*/i, "").trim());
            } else {
                points.push(line);
            }
        });

        if (overview) {
            body.append(
                $("<section>").addClass("history-ai-overview").append(
                    $("<h3>").text("Summary"),
                    $("<p>").text(overview)
                )
            );
        }

        if (points.length) {
            var pointsList = $("<ul>");
            points.forEach(function (point) {
                pointsList.append($("<li>").text(point));
            });

            body.append(
                $("<section>").addClass("history-ai-points").append(
                    $("<h3>").text("Key Points"),
                    pointsList
                )
            );
        }

        if (actions.length) {
            body.append(
                $("<section>").addClass("history-ai-action").append(
                    $("<h3>").text("Main Action"),
                    $("<p>").text(actions[0])
                )
            );
        }
    }

    function loadYearlyNarrative(year, previousYear, branchScope, isBranchScope) {
        var body = $("#historyNarrativeBody");
        if (!body.length) {
            return;
        }

        if (!year || typeof year !== "object") {
            renderYearlyNarrative([], "fallback", "No year selected.");
            return;
        }

        var yearKey = typeof year.year_key === "string" ? year.year_key : "";
        if (yearKey === "") {
            renderYearlyNarrative([], "fallback", "Invalid selected year.");
            return;
        }

        var normalizedBranchScope = String(branchScope || "").trim();
        var cacheKey = yearKey + "::" + normalizedBranchScope.toLowerCase();

        if (yearlyNarrativeCache[cacheKey]) {
            var cached = yearlyNarrativeCache[cacheKey];
            renderYearlyNarrative(cached.lines, cached.source, cached.meta);
            return;
        }

        body.empty().append($("<p>").addClass("history-ai-loading").text("Loading AI yearly narrative..."));
        $("#historyNarrativeMeta").text("Generating narrative...");

        var requestData = { year: yearKey, t: Date.now() };
        if (normalizedBranchScope !== "") {
            requestData.branch = normalizedBranchScope;
        }

        $.getJSON("ajax/get_ai_yearly_narrative.php", requestData)
            .done(function (data) {
                var lines = data && Array.isArray(data.narrative) ? normalizeLines(data.narrative) : [];
                var source = data && typeof data.source === "string" && data.source.trim() !== ""
                    ? data.source.trim().toUpperCase()
                    : "FALLBACK";
                var error = data && typeof data.error === "string" ? data.error.trim() : "";
                var aiStatus = data && typeof data.ai_status === "string" ? data.ai_status.trim() : "";
                var aiError = data && typeof data.ai_error === "string" ? data.ai_error.trim() : "";

                if (!lines.length) {
                    lines = buildYearlyNarrativeFallbackFromYear(year, previousYear, isBranchScope);
                    source = "FALLBACK";
                }

                var metaParts = [];
                if (error !== "") {
                    metaParts.push(error);
                } else {
                    metaParts.push("Source: " + source);
                }
                if (aiStatus !== "") {
                    metaParts.push("Status: " + aiStatus.replace(/_/g, " "));
                }
                if (aiError !== "") {
                    metaParts.push("Reason: " + aiError);
                }
                var metaMessage = metaParts.join(" | ");
                yearlyNarrativeCache[cacheKey] = {
                    lines: lines,
                    source: source,
                    meta: metaMessage
                };
                renderYearlyNarrative(lines, source, metaMessage);
            })
            .fail(function () {
                var fallbackLines = buildYearlyNarrativeFallbackFromYear(year, previousYear, isBranchScope);
                var metaMessage = "AI request failed. Showing formula-based fallback narrative.";
                yearlyNarrativeCache[cacheKey] = {
                    lines: fallbackLines,
                    source: "FALLBACK",
                    meta: metaMessage
                };
                renderYearlyNarrative(fallbackLines, "FALLBACK", metaMessage);
            });
    }

    function loadMonthlyComparisonHistoryPage() {
        var monthSelect = $("#historyMonthSelect");
        if (!monthSelect.length) {
            return;
        }

        setYearlyGraphVisibility(false);

        var historyBranchScope = getHistoryBranchScope();
        var isBranchScope = historyBranchScope !== "";
        setMonthlyHistoryTableMode(isBranchScope);
        var tableColspan = getHistoryTableColspan(isBranchScope);

        var tableBody = $("#historyTableBody");
        tableBody.empty().append(
            $("<tr>").append(
                $("<td>")
                    .attr("colspan", String(tableColspan))
                    .addClass("history-loading-cell")
                    .text("Loading monthly comparison...")
            )
        );

        var requestData = { t: Date.now() };
        if (historyBranchScope !== "") {
            requestData.branch = historyBranchScope;
        }

        $.getJSON("ajax/get_monthly_comparison_history.php", requestData)
            .done(function (payload) {
                var months = payload && Array.isArray(payload.months) ? payload.months : [];
                monthSelect.empty();

                if (!months.length) {
                    monthSelect.append($("<option>").val("").text("No month available"));
                    $("#historyMonthLabel").text("Monthly Scores");
                    $("#historyBranchCount").text("0 branches");
                    $("#historyAverageScore").text("-");
                    $("#historyTopBranch").text("-");
                    $("#historyBottomBranch").text("-");
                    $("#historyRiskCount").text("-");

                    tableBody.empty().append(
                        $("<tr>").append(
                            $("<td>")
                                .attr("colspan", String(tableColspan))
                                .addClass("history-empty-cell")
                                .text(
                                    historyBranchScope !== ""
                                        ? "No monthly history found for " + historyBranchScope + "."
                                        : "No monthly history found. Add reports with reporting_period to view month-to-month comparison."
                                )
                        )
                    );
                    renderMonthlyNarrative([], "fallback", "No monthly narrative available because no monthly data was found.");
                    return;
                }

                months.forEach(function (month) {
                    var monthKey = month && typeof month.month_key === "string" ? month.month_key : "";
                    var monthLabel = month && typeof month.month_label === "string" ? month.month_label : monthKey;
                    monthSelect.append($("<option>").val(monthKey).text(monthLabel));
                });

                function renderByMonthKey(monthKey) {
                    var match = null;
                    months.some(function (month) {
                        if (month && month.month_key === monthKey) {
                            match = month;
                            return true;
                        }
                        return false;
                    });

                    if (!match) {
                        match = months[0];
                    }

                    setMonthlyHistorySummary(match, isBranchScope);
                    renderMonthlyHistoryRows(match, isBranchScope);
                    loadMonthlyNarrative(match, historyBranchScope);
                }

                monthSelect.off("change.monthly").on("change.monthly", function () {
                    renderByMonthKey($(this).val());
                });

                monthSelect.val(months[0].month_key);
                renderByMonthKey(months[0].month_key);
            })
            .fail(function (xhr) {
                var message = "Failed to load monthly comparison history.";
                if (xhr.responseJSON && typeof xhr.responseJSON.error === "string" && xhr.responseJSON.error.trim() !== "") {
                    message = xhr.responseJSON.error.trim();
                }
                tableBody.empty().append(
                    $("<tr>").append(
                        $("<td>")
                            .attr("colspan", String(tableColspan))
                            .addClass("history-error-cell")
                            .text(message)
                    )
                );
                renderMonthlyNarrative([], "fallback", message);
            });
    }

    function loadYearlyComparisonHistoryPage() {
        var yearSelect = $("#historyMonthSelect");
        if (!yearSelect.length) {
            return;
        }

        setYearlyGraphVisibility(true);

        var historyBranchScope = getHistoryBranchScope();
        var isBranchScope = historyBranchScope !== "";
        configureYearlyChartTypeSwitcher(isBranchScope);
        setMonthlyHistoryTableMode(isBranchScope);
        var tableColspan = getHistoryTableColspan(isBranchScope);

        var tableBody = $("#historyTableBody");
        tableBody.empty().append(
            $("<tr>").append(
                $("<td>")
                    .attr("colspan", String(tableColspan))
                    .addClass("history-loading-cell")
                    .text("Loading yearly comparison...")
            )
        );

        var requestData = { t: Date.now() };
        if (historyBranchScope !== "") {
            requestData.branch = historyBranchScope;
        }

        $.getJSON("ajax/get_yearly_comparison_history.php", requestData)
            .done(function (payload) {
                var years = payload && Array.isArray(payload.years) ? payload.years : [];
                yearSelect.empty();

                if (!years.length) {
                    yearSelect.append($("<option>").val("").text("No year available"));
                    $("#historyMonthLabel").text("Yearly Scores");
                    $("#historyBranchCount").text("0 branches");
                    $("#historyAverageScore").text("-");
                    $("#historyTopBranch").text("-");
                    $("#historyBottomBranch").text("-");
                    $("#historyRiskCount").text("-");

                    tableBody.empty().append(
                        $("<tr>").append(
                            $("<td>")
                                .attr("colspan", String(tableColspan))
                                .addClass("history-empty-cell")
                                .text(
                                    historyBranchScope !== ""
                                        ? "No yearly history found for " + historyBranchScope + "."
                                        : "No yearly history found. Add reports with reporting_period across multiple years to view year-over-year comparison."
                                )
                        )
                    );
                    renderYearlyNarrative([], "fallback", "No yearly narrative available because no yearly data was found.");
                    showYearlyGraphEmpty("No yearly graph data available yet.");
                    return;
                }

                years.forEach(function (year) {
                    var yearKey = year && typeof year.year_key === "string" ? year.year_key : "";
                    var yearLabel = year && typeof year.year_label === "string" ? year.year_label : yearKey;
                    yearSelect.append($("<option>").val(yearKey).text(yearLabel));
                });

                function renderByYearKey(yearKey) {
                    var selectedIndex = -1;
                    var match = null;
                    years.some(function (year, index) {
                        if (year && year.year_key === yearKey) {
                            match = year;
                            selectedIndex = index;
                            return true;
                        }
                        return false;
                    });

                    if (!match) {
                        match = years[0];
                        selectedIndex = 0;
                    }

                    var previousYear = null;
                    if (selectedIndex >= 0 && selectedIndex + 1 < years.length) {
                        previousYear = years[selectedIndex + 1];
                    }

                    setYearlyHistorySummary(match, isBranchScope);
                    renderMonthlyHistoryRows(match, isBranchScope);
                    loadYearlyNarrative(match, previousYear, historyBranchScope, isBranchScope);
                    setYearlyGraphState(years, String(match.year_key || ""), isBranchScope);
                    renderYearlyTrendGraph(years, String(match.year_key || ""), isBranchScope);
                }

                yearSelect.off("change.yearly").on("change.yearly", function () {
                    renderByYearKey($(this).val());
                });

                yearSelect.val(years[0].year_key);
                renderByYearKey(years[0].year_key);
            })
            .fail(function (xhr) {
                var message = "Failed to load yearly comparison history.";
                if (xhr.responseJSON && typeof xhr.responseJSON.error === "string" && xhr.responseJSON.error.trim() !== "") {
                    message = xhr.responseJSON.error.trim();
                }
                tableBody.empty().append(
                    $("<tr>").append(
                        $("<td>")
                            .attr("colspan", String(tableColspan))
                            .addClass("history-error-cell")
                            .text(message)
                    )
                );
                renderYearlyNarrative([], "fallback", message);
                showYearlyGraphEmpty(message);
            });
    }
    function setInterpretationLines(lines, source) {
        var panel = $("#interpretationPanel");
        if (!panel.length) {
            return;
        }

        panel.empty();

        var cleanLines = normalizeLines(lines);
        if (!cleanLines.length) {
            panel.append($("<p>").addClass("ai-empty").text("No interpretation available right now."));
            return;
        }

        var overview = cleanLines[0];
        var points = [];
        var actions = [];
        var actionPattern = /^(?:[-*•]\s*)?(Main action|Priority action|Recommended action)\s*:/i;
        function extractActionText(line) {
            return String(line || "").replace(actionPattern, "").trim();
        }

        cleanLines.slice(1).forEach(function (line) {
            if (actionPattern.test(line)) {
                actions.push(extractActionText(line));
            } else {
                points.push(line);
            }
        });

        // Keep summary to one sentence and move any extra sentences into key points.
        var overviewSentences = splitSentences(overview);
        if (overviewSentences.length) {
            overview = overviewSentences[0];
            if (overviewSentences.length > 1) {
                points = overviewSentences.slice(1).concat(points);
            }
        }

        // Normalize key points as bullet-ready sentence items.
        points = points.reduce(function (acc, line) {
            var sentences = splitSentences(String(line || ""));
            if (!sentences.length) {
                var clean = String(line || "").trim();
                if (clean !== "") {
                    if (actionPattern.test(clean)) {
                        actions.push(extractActionText(clean));
                    } else {
                        acc.push(clean);
                    }
                }
                return acc;
            }
            sentences.forEach(function (sentence) {
                var cleanSentence = sentence.trim();
                if (cleanSentence !== "") {
                    if (actionPattern.test(cleanSentence)) {
                        actions.push(extractActionText(cleanSentence));
                    } else {
                        acc.push(cleanSentence);
                    }
                }
            });
            return acc;
        }, []);
        var sourceValue = typeof source === "string" ? source.trim().toLowerCase() : "";
        var isAiSource = sourceValue === "ai";

        if (overview) {
            var overviewHeader = $("<div>")
                .addClass("ai-detail-head")
                .append($("<h4>").text("AI Summary"))
                .append(
                    $("<span>")
                        .addClass("ai-source-badge")
                        .addClass(isAiSource ? "ai-source-ai" : "ai-source-fallback")
                        .attr("title", isAiSource ? "AI generated" : "Fallback generated")
                        .append($("<span>").addClass("ai-source-text").text("AI"))
                );

            panel.append(
                $("<section>").addClass("ai-detail-overview").append(
                    overviewHeader,
                    $("<p>").text(overview)
                )
            );
        }

        var keyPointsSection = $("<section>").addClass("ai-detail-section").append(
            $("<h4>").text("AI key points")
        );
        var keyPoints = $("<ul>").addClass("ai-detail-points");
        if (points.length) {
            points.forEach(function (point) {
                keyPoints.append($("<li>").text(point));
            });
        } else {
            keyPoints.append($("<li>").addClass("ai-empty").text("No additional key points available right now."));
        }
        keyPointsSection.append(keyPoints);
        panel.append(keyPointsSection);

        if (actions.length) {
            var actionWrap = $("<section>").addClass("ai-detail-action").append(
                $("<h4>").text("Main action"),
                $("<p>").text(actions[0])
            );

            panel.append(actionWrap);
        }
    }

    function loadBranchAiInterpretation(branchName, fallbackLines) {
        var panel = $("#interpretationPanel");
        if (!panel.length) {
            return;
        }

        panel.empty().append($("<p>").addClass("ai-loading").text("Loading AI interpretation..."));

        $.getJSON("ajax/get_ai_branch_interpretation.php", { branch: branchName, t: Date.now() })
            .done(function (data) {
                var aiLines = [];
                if (data && Array.isArray(data.interpretation)) {
                    aiLines = normalizeLines(data.interpretation);
                }

                if (aiLines.length) {
                    var sourceValue = data && typeof data.source === "string" ? data.source.trim().toLowerCase() : "ai";
                    setInterpretationLines(aiLines, sourceValue === "ai" ? "ai" : "fallback");
                    return;
                }

                if (fallbackLines.length) {
                    setInterpretationLines(fallbackLines, "fallback");
                    return;
                }

                var message = "No interpretation available right now.";
                if (data && typeof data.error === "string" && data.error.trim() !== "") {
                    message = data.error.trim();
                }
                setInterpretationLines([message], "fallback");
            })
            .fail(function (xhr) {
                if (fallbackLines.length) {
                    setInterpretationLines(fallbackLines, "fallback");
                    return;
                }

                var message = "Error loading interpretation.";
                if (xhr.responseJSON && typeof xhr.responseJSON.error === "string" && xhr.responseJSON.error.trim() !== "") {
                    message = xhr.responseJSON.error.trim();
                }
                setInterpretationLines([message], "fallback");
            });
    }

    function formatAiStatus(statusValue) {
        var clean = String(statusValue || "").trim().toLowerCase();
        if (clean === "") {
            return "unknown";
        }
        return clean.replace(/_/g, " ");
    }

    function renderOverviewInsights(summaryText, recommendations, source, aiStatus, aiError) {
        var insightBox = $(".aside-right-insight");
        if (!insightBox.length) {
            return;
        }

        insightBox.empty();

        var summaryCard = $("<section>").addClass("ai-summary-card");
        var sourceValue = typeof source === "string" ? source.trim().toLowerCase() : "";
        var isAiSource = sourceValue === "ai";
        var sourceBadge = $("<span>")
            .addClass("ai-source-badge")
            .addClass(isAiSource ? "ai-source-ai" : "ai-source-fallback")
            .attr("title", isAiSource ? "AI generated" : "Fallback generated")
            .append($("<span>").addClass("ai-source-text").text(isAiSource ? "AI" : "AI"));

        var summaryHead = $("<div>")
            .addClass("ai-summary-head")
            .append($("<h3>").text("Quick Read"))
            .append(sourceBadge);
        summaryCard.append(summaryHead);

        var statusText = "Status: " + formatAiStatus(aiStatus || (isAiSource ? "ok" : "fallback"));
        summaryCard.append($("<p>").addClass("ai-summary-meta").text(statusText));
        if (typeof aiError === "string" && aiError.trim() !== "") {
            summaryCard.append(
                $("<p>")
                    .addClass("ai-summary-meta")
                    .text("Reason: " + aiError.trim())
            );
        }

        var formattedSummaryText = formatAiInsightText(summaryText);
        var sentences = splitSentences(formattedSummaryText);
        if (sentences.length) {
            summaryCard.append($("<p>").addClass("ai-summary-lead").text(sentences[0]));
            if (sentences.length > 1) {
                var points = $("<ul>").addClass("ai-summary-points");
                sentences.slice(1).forEach(function (line) {
                    points.append($("<li>").text(line));
                });
                summaryCard.append(points);
            }
        } else {
            summaryCard.append($("<p>").addClass("ai-summary-lead").text("No insight summary available right now."));
        }

        insightBox.append(summaryCard);

        var recSection = $("<section>").addClass("ai-reco-section");
        recSection.append($("<h3>").addClass("ai-section-title").text("Top Actions"));

        if (!Array.isArray(recommendations) || !recommendations.length) {
            recSection.append($("<p>").addClass("ai-empty").text("No recommendation available right now."));
            insightBox.append(recSection);
            return;
        }

        var recList = $("<div>").addClass("ai-reco-list");
        recommendations.forEach(function (rec) {
            var action = "";
            var reason = "";
            var priority = "";

            if (rec && typeof rec === "object") {
                action = typeof rec.action === "string" ? rec.action.trim() : "";
                reason = typeof rec.reason === "string" ? rec.reason.trim() : "";
                priority = typeof rec.priority === "string" ? rec.priority.trim() : "";
            } else {
                action = String(rec || "").trim();
            }

            action = formatAiInsightText(action);
            reason = formatAiInsightText(reason);

            if (action === "") {
                action = "Recommendation available.";
            }

            var item = $("<article>").addClass("ai-reco-item");
            var head = $("<div>").addClass("ai-reco-head");

            head.append(
                $("<span>")
                    .addClass("ai-priority-chip")
                    .addClass(priorityClass(priority))
                    .text(priorityLabel(priority))
            );
            head.append($("<h4>").text(action));

            item.append(head);

            if (reason !== "") {
                item.append($("<p>").addClass("ai-reco-reason").text(reason));
            }

            recList.append(item);
        });

        recSection.append(recList);
        insightBox.append(recSection);
    }

    function loadOverviewInsights() {
        var insightBox = $(".aside-right-insight");
        if (!insightBox.length) {
            return;
        }

        insightBox.empty().append($("<p>").addClass("ai-loading").text("Loading AI insights..."));

        $.getJSON("ajax/get_ai_insights.php", { t: Date.now() })
            .done(function (data) {
                var summaryText = data && typeof data.summary === "string" ? data.summary.trim() : "";
                var recommendations = data && Array.isArray(data.recommendations) ? data.recommendations : [];
                var source = data && typeof data.source === "string" ? data.source.trim() : "fallback";
                var aiStatus = data && typeof data.ai_status === "string" ? data.ai_status.trim() : "";
                var aiError = data && typeof data.ai_error === "string" ? data.ai_error.trim() : "";
                renderOverviewInsights(summaryText, recommendations, source, aiStatus, aiError);
            })
            .fail(function (xhr) {
                var message = "Error loading AI insights.";
                if (xhr.responseJSON && typeof xhr.responseJSON.summary === "string" && xhr.responseJSON.summary.trim() !== "") {
                    message = xhr.responseJSON.summary.trim();
                }
                insightBox.empty().append($("<p>").addClass("ai-error").text(message));
            });
    }

    function parseIsoLikeDate(value) {
        var clean = String(value || "").trim();
        if (clean === "") {
            return null;
        }

        var dateOnlyMatch = clean.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (dateOnlyMatch) {
            return new Date(
                Number(dateOnlyMatch[1]),
                Number(dateOnlyMatch[2]) - 1,
                Number(dateOnlyMatch[3])
            );
        }

        var timestamp = Date.parse(clean);
        if (Number.isNaN(timestamp)) {
            return null;
        }

        var parsed = new Date(timestamp);
        return new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
    }

    function formatMonthDay(dateValue) {
        return dateValue.toLocaleDateString([], {
            month: "short",
            day: "numeric"
        });
    }

    function formatShortDate(dateValue) {
        return dateValue.toLocaleDateString([], {
            month: "short",
            day: "numeric",
            year: "numeric"
        });
    }

    function buildReportingRangeLabel(health) {
        var startDate = parseIsoLikeDate(health && health.reporting_range_start);
        var endDate = parseIsoLikeDate(health && health.reporting_range_end);

        if (startDate && endDate) {
            if (startDate.getTime() > endDate.getTime()) {
                var swapDate = startDate;
                startDate = endDate;
                endDate = swapDate;
            }

            if (startDate.getTime() === endDate.getTime()) {
                return "Data period: " + formatShortDate(startDate);
            }

            if (startDate.getFullYear() === endDate.getFullYear()) {
                if (startDate.getMonth() === endDate.getMonth()) {
                    return "Data period: "
                        + startDate.toLocaleDateString([], { month: "short" })
                        + " "
                        + startDate.getDate()
                        + "-"
                        + endDate.getDate()
                        + ", "
                        + startDate.getFullYear();
                }

                return "Data period: "
                    + formatMonthDay(startDate)
                    + " - "
                    + formatMonthDay(endDate)
                    + ", "
                    + startDate.getFullYear();
            }

            return "Data period: " + formatShortDate(startDate) + " - " + formatShortDate(endDate);
        }

        var periodDate = parseIsoLikeDate(health && health.reporting_period);
        if (periodDate) {
            return "Data period: " + periodDate.toLocaleDateString([], {
                month: "long",
                year: "numeric"
            });
        }

        return "Data period: Not specified";
    }

    initComparisonHistory();

    if ($("#historyMonthSelect").length) {
        var historyBranchScope = getHistoryBranchScope();
        var activeHistoryPeriod = getHistoryPeriod();
        applyHistoryPeriodPresentation(activeHistoryPeriod);
        initHistoryPeriodSwitcher(activeHistoryPeriod, historyBranchScope);

        if (activeHistoryPeriod === "yearly") {
            loadYearlyComparisonHistoryPage();
        } else {
            loadMonthlyComparisonHistoryPage();
        }
    }

    function bindMobileInsightsJump() {
        var jumpButton = $("#jumpInsightsBtn");
        if (!jumpButton.length) {
            return;
        }
        var jumpState = "insights";
        var insightsLabel = "AI Insights";
        var cardsLabel = "Cards";

        function setJumpButtonState(state) {
            var normalizedState = state === "cards" ? "cards" : "insights";
            jumpState = normalizedState;
            if (normalizedState === "cards") {
                jumpButton
                    .text(cardsLabel)
                    .attr("aria-controls", "dashboardCardsPanel")
                    .attr("aria-label", "Jump to cards")
                    .addClass("is-toggle-back");
                return;
            }

            jumpButton
                .text(insightsLabel)
                .attr("aria-controls", "dashboardInsights")
                .attr("aria-label", "Jump to AI insights")
                .removeClass("is-toggle-back");
        }

        function resolveInsightsTopOffset() {
            var topControls = $(".dashboard-page .top-controls");
            var stickyHeight = topControls.length ? Math.ceil(topControls.outerHeight() || 0) : 0;
            var extraOffset = window.matchMedia("(max-width: 1024px)").matches ? 2 : 8;
            return Math.max(0, stickyHeight + extraOffset);
        }

        function syncInsightsScrollMargin() {
            var insightsPanel = $("#dashboardInsights");
            if (!insightsPanel.length) {
                return 0;
            }
            var offset = resolveInsightsTopOffset();
            insightsPanel.css("scroll-margin-top", offset + "px");
            return offset;
        }

        function syncJumpButtonState() {
            var insightsPanel = $("#dashboardInsights");
            if (!insightsPanel.length) {
                return;
            }

            var offset = resolveInsightsTopOffset();
            var panelTop = insightsPanel.offset() ? insightsPanel.offset().top : 0;
            var scrollTop = $(window).scrollTop() || 0;
            var isNearInsights = (scrollTop + offset) >= (panelTop - 12);
            setJumpButtonState(isNearInsights ? "cards" : "insights");
        }

        syncInsightsScrollMargin();
        setJumpButtonState("insights");
        syncJumpButtonState();
        $(window)
            .off("resize.jumpInsights orientationchange.jumpInsights scroll.jumpInsights")
            .on("resize.jumpInsights orientationchange.jumpInsights", function () {
                syncInsightsScrollMargin();
                syncJumpButtonState();
            })
            .on("scroll.jumpInsights", function () {
                syncJumpButtonState();
            });

        jumpButton.off("click.jumpInsights").on("click.jumpInsights", function () {
            var insightsPanel = $("#dashboardInsights");
            var cardsPanel = $("#dashboardCardsPanel");
            if (!insightsPanel.length && !cardsPanel.length) {
                return;
            }

            var offset = syncInsightsScrollMargin();
            var targetPanel = jumpState === "cards" ? cardsPanel : insightsPanel;
            if (!targetPanel.length) {
                targetPanel = insightsPanel.length ? insightsPanel : cardsPanel;
            }
            if (!targetPanel.length) {
                return;
            }

            var targetTop = Math.max(0, Math.round((targetPanel.offset() ? targetPanel.offset().top : 0) - offset));
            $("html, body").stop(true).animate({ scrollTop: targetTop }, 240, function () {
                syncJumpButtonState();
            });
        });
    }

    // DASHBOARD (INDEX)
    if ($("#branchCards").length) {
        bindMobileInsightsJump();
        var cards = $("#branchCards");
        var DASHBOARD_VIEW_STORAGE_KEY = "fh_dashboard_view_mode_v1";
        var allowedStatusKeys = {
            excellent: true,
            good: true,
            warning: true,
            critical: true
        };

        function escapeHtml(value) {
            return String(value == null ? "" : value)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        function normalizeViewMode(mode) {
            return mode === "compact" ? "compact" : "cards";
        }

        function readDashboardViewMode() {
            if (typeof window === "undefined" || !window.localStorage) {
                return "cards";
            }
            try {
                return normalizeViewMode(window.localStorage.getItem(DASHBOARD_VIEW_STORAGE_KEY));
            } catch (e) {
                return "cards";
            }
        }

        function writeDashboardViewMode(mode) {
            if (typeof window === "undefined" || !window.localStorage) {
                return;
            }
            try {
                window.localStorage.setItem(DASHBOARD_VIEW_STORAGE_KEY, normalizeViewMode(mode));
            } catch (e) {
                // ignore storage errors
            }
        }

        function applyDashboardViewMode(mode) {
            var normalizedMode = normalizeViewMode(mode);
            var body = $("body.dashboard-page");
            body.toggleClass("view-compact", normalizedMode === "compact");
            body.toggleClass("view-cards", normalizedMode !== "compact");

            $("#viewCardsBtn")
                .toggleClass("is-active", normalizedMode === "cards")
                .attr("aria-pressed", normalizedMode === "cards" ? "true" : "false");
            $("#viewCompactBtn")
                .toggleClass("is-active", normalizedMode === "compact")
                .attr("aria-pressed", normalizedMode === "compact" ? "true" : "false");

            writeDashboardViewMode(normalizedMode);
        }

        function bindDashboardViewToggle() {
            $("#viewCardsBtn, #viewCompactBtn")
                .off("click.dashboardView")
                .on("click.dashboardView", function () {
                    applyDashboardViewMode($(this).attr("data-view"));
                });
        }

        function renderBranchCards(branches) {
            cards.empty();

            if (!Array.isArray(branches) || !branches.length) {
                cards.append($("<p>").text("No branches found."));
                return;
            }

            branches.forEach(function (health) {
                var branchName = String(health && health.branch_name ? health.branch_name : "").trim();
                if (branchName === "") {
                    branchName = "Unknown Branch";
                }

                var statusKey = String(health && health.status_key ? health.status_key : "").toLowerCase();
                if (!Object.prototype.hasOwnProperty.call(allowedStatusKeys, statusKey)) {
                    statusKey = "good";
                }

                var statusLabel = String(health && health.status ? health.status : "N/A").trim();
                if (statusLabel === "") {
                    statusLabel = "N/A";
                }

                var statusText = String(health && health.status_text ? health.status_text : "No remark").trim();
                if (statusText === "") {
                    statusText = "No remark";
                }

                var scoreValue = Number(health && health.overall_score);
                if (!Number.isFinite(scoreValue)) {
                    scoreValue = 0;
                }
                var normalizedScore = Math.max(0, Math.min(100, Math.round(scoreValue)));

                var reportingRangeLabel = buildReportingRangeLabel(health);
                var detailUrl = "detail.php?branch=" + encodeURIComponent(branchName);

                var card = `
                    <article class="health-card ${statusKey}" data-detail-url="${detailUrl}" tabindex="0" role="link" aria-label="View details for ${escapeHtml(branchName)}">
                        <div class="health-card-head">
                            <h3 title="${escapeHtml(branchName)}">${escapeHtml(branchName)}</h3>
                            <span class="badge">${escapeHtml(statusLabel)}</span>
                        </div>
                        <div class="score">${normalizedScore}<span>/100</span></div>
                        <p class="reporting-range">${escapeHtml(reportingRangeLabel)}</p>
                        <div class="progress">
                            <div class="progress-bar" style="width:${normalizedScore}%"></div>
                        </div>
                        <p class="remark">${escapeHtml(statusText)}</p>
                        <a href="${detailUrl}" class="btn btn-detail" aria-label="View details for ${escapeHtml(branchName)}" title="View details">
                            <span class="btn-label">Details</span>
                            <span class="btn-icon" aria-hidden="true">&gt;</span>
                        </a>
                    </article>
                `;
                cards.append(card);
            });
        }

        function applyFilters() {
            var selectedStatus = String($("#statusFilter").val() || "all").toLowerCase();
            var searchValue = String($("#searchBranch").val() || "").toLowerCase().trim();

            cards.find(".health-card").each(function () {
                var card = $(this);
                var branchLabel = card.find("h3").text().toLowerCase();
                var matchesStatus = selectedStatus === "all" || card.hasClass(selectedStatus);
                var matchesSearch = branchLabel.indexOf(searchValue) !== -1;
                card.toggle(matchesStatus && matchesSearch);
            });
        }

        cards
            .off("click.cardOpen")
            .on("click.cardOpen", ".health-card", function (event) {
                if ($(event.target).closest("a, button, input, select, textarea, label").length) {
                    return;
                }
                var detailUrl = $(this).attr("data-detail-url");
                if (detailUrl) {
                    window.location.href = detailUrl;
                }
            })
            .off("keydown.cardOpen")
            .on("keydown.cardOpen", ".health-card", function (event) {
                if (event.key !== "Enter" && event.key !== " ") {
                    return;
                }
                event.preventDefault();
                var detailUrl = $(this).attr("data-detail-url");
                if (detailUrl) {
                    window.location.href = detailUrl;
                }
            });

        bindDashboardViewToggle();
        applyDashboardViewMode(readDashboardViewMode());

        $("#statusFilter")
            .off("change.dashboardFilter")
            .on("change.dashboardFilter", applyFilters);
        $("#searchBranch")
            .off("input.dashboardFilter")
            .on("input.dashboardFilter", applyFilters);

        $.getJSON("ajax/get_health_list.php")
            .done(function (branches) {
                renderBranchCards(branches);
                applyFilters();
            })
            .fail(function () {
                $("#branchCards").empty().append($("<p>").text("Failed to load branch list."));
            });

        loadOverviewInsights();
    }

    // DETAIL PAGE
    if ($("#branchTitle").length) {
        var fromInline = typeof selectedBranch === "string" ? selectedBranch.trim() : "";
        var fromQuery = (new URLSearchParams(window.location.search).get("branch") || "").trim();
        var branch = fromInline !== "" ? fromInline : fromQuery;

        if (!branch) {
            alert("No branch selected.");
            return;
        }

        $.getJSON("ajax/get_health_detail.php", { branch: branch })
            .done(function (data) {
                if (data.error) {
                    alert(data.error);
                    return;
                }

                $("#branchTitle").text(data.branch_name);
                $("#branchSubtitle").text(data.status + " - " + data.status_text);
                $("#overallScore").text(data.overall_score + "/100");
                $("#progressBar").css("width", data.overall_score + "%");

                $("#healthCard")
                    .removeClass("excellent good warning critical")
                    .addClass(data.status_key);

                $("#factorTableBody").empty();

                data.factors.forEach(function (factor) {
                    var row = `
                        <tr>
                            <td>${factor.name}</td>
                            <td>${factor.raw_basis}</td>
                            <td>${factor.score}</td>
                            <td>${factor.weight}%</td>
                        </tr>
                    `;
                    $("#factorTableBody").append(row);
                });

                var baseInterpretation = normalizeLines(data.interpretation);
                loadBranchAiInterpretation(data.branch_name || branch, baseInterpretation);

                document.title = data.branch_name + " | FH";
            })
            .fail(function () {
                alert("Failed to load branch data.");
            });
    }
});
