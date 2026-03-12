# Franchise Health Module

## Read Me First

This module is designed for branch health analysis using:

- deterministic scoring formulas (source of truth)
- AI-generated narrative explanations (assistant layer)
- owner-friendly health cards and detail views (presentation layer)

For production, keep this rule:

- formulas compute scores
- AI explains scores
- AI does not replace formula decisions

## What It Does

- Calculates branch health scores from financial and operational KPIs.
- Classifies branch status (`EXCELLENT`, `GOOD`, `WARNING`, `CRITICAL`).
- Renders summary cards and branch detail pages.
- Generates AI quick reads and branch interpretations, grounded on computed data.

## Current Architecture

- `app/Core/AppFactory.php`: composition root for wiring repositories/services/controllers
- `app/Controllers`: HTTP entry logic
- `services/FranchiseHealthService.php`: score orchestration per branch
- `services/HealthScoring.php`: scoring formulas and weighted score model
- `services/AIInsightsService.php`: AI narrative generation with deterministic fallback
- `app/Models/BranchRepositoryInterface.php`: data contract
- `app/Models/ClickHouseBranchRepository.php`: ClickHouse HTTP data adapter for branch reports
- `app/Models/SupabaseBranchRepository.php`: Supabase REST data adapter for branch reports
- `app/Models/RealReportRepository.php`: MySQL/PDO data adapter for branch reports
- `assets/js/franchise-health.js`: dashboard/detail rendering and AJAX calls

Request flow:

- View/AJAX script -> Controller -> Service -> Repository -> Response (JSON or View)

## Data Contract (Required Fields Per Branch)

Each branch row must provide:

- `branch`
- `current_sales`
- `previous_sales`
- `expenses`
- `cogs`
- `avg_inventory`
- `dead_stock`
- `expected_pos_days`
- `actual_pos_days`

## Scoring Logic (Deterministic)

Implemented in `services/HealthScoring.php`.

- Sales Performance: 30%
- Net Income: 25%
- Inventory Health: 20%
- Expense Control: 15%
- Activity/Compliance: 10%

Overall score:

- `round(sales*0.30 + net_income*0.25 + inventory*0.20 + expenses*0.15 + activity*0.10)`

Status mapping:

- `>= 90`: `EXCELLENT`
- `>= 80`: `GOOD`
- `>= 60`: `WARNING`
- `< 60`: `CRITICAL`

## AI Behavior

Implemented in `services/AIInsightsService.php`.

- AI input includes computed metrics, score factors, and formula references.
- AI output is validated for numeric grounding.
- If AI response is invalid/unavailable, deterministic text fallback is returned.

## API Endpoints

- `ajax/get_health_list.php`
- `ajax/get_health_detail.php?branch=...`
- `ajax/get_ai_insights.php`
- `ajax/get_ai_branch_interpretation.php?branch=...`
- `ajax/get_ai_monthly_narrative.php?month=YYYY-MM&branch=...` (`branch` optional)
- `ajax/get_ai_yearly_narrative.php?year=YYYY&branch=...` (`branch` optional)
- `ajax/get_monthly_comparison_history.php?branch=...` (`branch` optional)
- `ajax/get_yearly_comparison_history.php?branch=...` (`branch` optional)

Pages:

- `index.php` (overview)
- `detail.php?branch=...` (branch detail)
- `history.php?period=monthly` (monthly comparison history)
- `history.php?period=yearly` (yearly comparison history)

## Local Setup

1. Put project under your XAMPP `htdocs`.
2. Make sure PHP has `curl` enabled.
3. Open `http://localhost/Franchise%20Health%20module/`.
4. Optional AI setup: add `.env` in project root:

```env
GROQ_API_KEY=your_key_here
GROQ_MODEL=llama-3.1-8b-instant
AI_YEARLY_CACHE_TABLE=ai_yearly_narrative_cache
AI_CACHE_IMPACT_MONEY_STEP=5000
AI_CACHE_IMPACT_PERCENT_POINT_STEP=0.5
AI_CACHE_IMPACT_SCORE_STEP=1
```

`AI_YEARLY_CACHE_TABLE` is optional; if not set, yearly narrative cache falls back to `AI_MONTHLY_CACHE_TABLE`.
`AI_CACHE_IMPACT_*` controls when dashboard AI cache is considered "meaningfully changed" before regenerating:

- `AI_CACHE_IMPACT_MONEY_STEP`: bucket size in PHP for monetary fields (default `5000`)
- `AI_CACHE_IMPACT_PERCENT_POINT_STEP`: bucket size in percentage points for ratio fields (default `0.5`)
- `AI_CACHE_IMPACT_SCORE_STEP`: bucket size for score fields (default `1`)

If API key is missing, the app still works with deterministic fallback insights.

## Real Data Setup

The app reads live report rows through a configured repository:

- `BRANCH_DATA_SOURCE=clickhouse` -> `ClickHouseBranchRepository`
- `BRANCH_DATA_SOURCE=supabase` -> `SupabaseBranchRepository`
- `BRANCH_DATA_SOURCE=mysql` (or `db`/`real`) -> `RealReportRepository`

1. Set `BRANCH_DATA_SOURCE` to your active production source.
2. Add source-specific credentials to `.env`.
3. Ensure your source table/view includes the required contract fields.
4. Include either `reporting_period` or `month` so `history.php` can group results by month and year.

Example `.env` values:

ClickHouse example:

```env
BRANCH_DATA_SOURCE=clickhouse
CLICKHOUSE_URL=http://localhost:8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USER=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_SOURCE_MODE=table
CLICKHOUSE_TABLE=branch_reports
CLICKHOUSE_COL_REPORTING_PERIOD=reporting_period
HISTORY_MONTH_LIMIT=12
HISTORY_YEAR_MONTH_LIMIT=60
```

`HISTORY_MONTH_LIMIT` controls how many recent months are loaded by monthly history endpoints (default `12`, max `60`).
`HISTORY_YEAR_MONTH_LIMIT` controls how many recent months are scanned to build yearly history (default `60`, max `60`).

## Production Readiness Checklist

- Set `BRANCH_DATA_SOURCE` to a real source (`clickhouse`, `supabase`, or `mysql`) and keep it fixed per environment.
- Validate and sanitize incoming report data before scoring.
- Add authentication/authorization to API routes.
- Prevent XSS by avoiding raw HTML interpolation for API values in frontend.
- Add structured logs and monitoring for AI and scoring failures.
- Add automated tests for formulas and API contracts.
