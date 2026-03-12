# ClickHouse Data Lineage (Franchise Health Module)

## 1) Runtime Source Selection

- `BRANCH_DATA_SOURCE=clickhouse` selects `ClickHouseBranchRepository`.
- `CLICKHOUSE_SOURCE_MODE=dw_derived` enables the derived-query path (not the direct `branch_reports` table path).

## 2) Effective ClickHouse Source Tables

When `CLICKHOUSE_SOURCE_MODE=dw_derived`, the app reads from:

1. `dw_sales_summary`
2. `dw_sales_report`
3. `dw_admin_cashier_report`

## 3) Key Fields Consumed

- `dw_sales_summary`:
  - `branch_name`
  - `report_date`
  - `grand_total`
- `dw_sales_report`:
  - `branch_name`
  - `date_created`
  - `total_sales`
  - `cost_of_goods`
- `dw_admin_cashier_report`:
  - `branch`
  - `date`
  - `total_expenses`

## 4) Metric Construction in Derived SQL

The query builds monthly branch-level metrics used by health scoring and AI:

- `current_sales`: monthly sum of sales
  - prefers `dw_sales_summary` where available
  - falls back to `dw_sales_report` when summary is delayed
- `previous_sales`: prior month sales via window function
- `expenses`: monthly sum from `dw_admin_cashier_report`
- `cogs`: monthly sum from `dw_sales_report`
- `reporting_period`: month date (`toDate(month_start)`)

Output metrics passed upstream:

- `branch`
- `current_sales`
- `previous_sales`
- `expenses`
- `cogs`
- `avg_inventory` (currently fixed to `0` in derived query)
- `dead_stock` (currently fixed to `0` in derived query)
- `expected_pos_days`
- `actual_pos_days`
- `reporting_period`

## 5) Downstream Usage

- `FranchiseHealthService` consumes repository output through:
  - `getBranches()`
  - `getMonthlyReports()`
- `AIInsightsService` uses those computed branch/month records for overview, branch interpretation, and monthly narratives.

## 6) Freshness Snapshot (Checked 2026-02-26, ClickHouse tz: Asia/Manila)

Observed latest timestamps and recent activity:

- `dw_sales_report`
  - latest timestamp: `2026-02-26 07:23:49`
  - rows in last 24h: `19`
  - rows in last 6h: `2`
- `dw_admin_cashier_report`
  - latest timestamp: `2026-01-02 10:25:00`
  - rows in last 24h: `0`
  - rows in last 6h: `0`
- `dw_sales_summary`
  - latest timestamp: `2025-12-04 00:00:00`
  - rows in last 24h: `0`
  - rows in last 6h: `0`

Interpretation:

- Data pipeline appears partially fresh: sales-side source is updating, while expenses/summary sources appear stale.

## 7) Verification SQL (Read-Only)

```sql
WITH now() AS now_ts
SELECT *
FROM (
    SELECT
        'dw_sales_summary' AS source,
        toString(max(toDateTime(report_date))) AS latest_ts,
        countIf(toDateTime(report_date) >= now_ts - INTERVAL 24 HOUR) AS rows_24h,
        countIf(toDateTime(report_date) >= now_ts - INTERVAL 6 HOUR) AS rows_6h
    FROM dw_sales_summary
    UNION ALL
    SELECT
        'dw_sales_report' AS source,
        toString(max(parseDateTimeBestEffortOrNull(toString(date_created)))) AS latest_ts,
        countIf(parseDateTimeBestEffortOrNull(toString(date_created)) >= now_ts - INTERVAL 24 HOUR) AS rows_24h,
        countIf(parseDateTimeBestEffortOrNull(toString(date_created)) >= now_ts - INTERVAL 6 HOUR) AS rows_6h
    FROM dw_sales_report
    UNION ALL
    SELECT
        'dw_admin_cashier_report' AS source,
        toString(max(parseDateTimeBestEffortOrNull(toString(date)))) AS latest_ts,
        countIf(parseDateTimeBestEffortOrNull(toString(date)) >= now_ts - INTERVAL 24 HOUR) AS rows_24h,
        countIf(parseDateTimeBestEffortOrNull(toString(date)) >= now_ts - INTERVAL 6 HOUR) AS rows_6h
    FROM dw_admin_cashier_report
)
ORDER BY source;
```

## 8) Notes for Reporting

- If stakeholders expect hourly refresh for all metrics, focus incident checks on ETL/loads into:
  - `dw_admin_cashier_report`
  - `dw_sales_summary`
- The app logic is currently functioning as configured; stale inputs will still lead to stale derived outputs.
