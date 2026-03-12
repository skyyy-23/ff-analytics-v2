# Franchise Health System Flow Diagram

This document maps how pages, routes, controllers, services, repositories, AI, and data sources connect.

## 1) End-to-End Architecture

```mermaid
flowchart LR
    %% =========================
    %% Client / UI Layer
    %% =========================
    subgraph CLIENT["Client (Browser)"]
        BROWSER["Browser"]
        VIEW_INDEX["app/Views/dashboard/index.php"]
        VIEW_DETAIL["app/Views/dashboard/detail.php"]
        VIEW_HISTORY["app/Views/dashboard/history.php"]
        JS["assets/js/franchise-health.js"]
    end

    %% =========================
    %% Page Entry Routes
    %% =========================
    subgraph PAGES["Page Entry Files"]
        PAGE_INDEX["index.php"]
        PAGE_DETAIL["detail.php"]
        PAGE_HISTORY["history.php"]
    end

    %% =========================
    %% AJAX Routes
    %% =========================
    subgraph AJAX["AJAX Route Files (ajax/*.php)"]
        A_HEALTH_LIST["get_health_list.php"]
        A_HEALTH_DETAIL["get_health_detail.php"]
        A_MONTHLY_HISTORY["get_monthly_comparison_history.php"]
        A_YEARLY_HISTORY["get_yearly_comparison_history.php"]
        A_AI_OVERVIEW["get_ai_insights.php"]
        A_AI_BRANCH["get_ai_branch_interpretation.php"]
        A_AI_MONTHLY["get_ai_monthly_narrative.php"]
        A_AI_YEARLY["get_ai_yearly_narrative.php"]
    end

    %% =========================
    %% App Core
    %% =========================
    subgraph CORE["Core / Wiring"]
        BOOT["app/bootstrap.php"]
        FACTORY["app/Core/AppFactory.php"]
        REQUEST["app/Core/Request.php"]
        CONTROLLER_BASE["app/Core/Controller.php"]
        ENV["services/Env.php (.env loader)"]
    end

    %% =========================
    %% Controllers
    %% =========================
    subgraph CONTROLLERS["Controllers"]
        C_DASH["DashboardController"]
        C_HEALTH["HealthApiController"]
        C_INSIGHTS["InsightsApiController"]
    end

    %% =========================
    %% Services
    %% =========================
    subgraph SERVICES["Services"]
        S_HEALTH["FranchiseHealthService"]
        S_SCORING["HealthScoring"]
        S_STATUS["StatusCode"]
        S_INTERP["InterpretationCode"]
        S_AI["AIInsightsService"]
        S_GROQ["GroqClient"]
    end

    %% =========================
    %% Repository Abstraction
    %% =========================
    subgraph REPOS["Data Access (Repository Pattern)"]
        I_REPO["BranchRepositoryInterface"]
        R_CLICK["ClickHouseBranchRepository"]
        R_SUPA["SupabaseBranchRepository"]
        R_MYSQL["RealReportRepository"]
    end

    %% =========================
    %% External Data Sources
    %% =========================
    subgraph DATA["Data / External APIs"]
        D_CLICK["ClickHouse\n(branch_reports OR dw_derived SQL)"]
        D_SUPA["Supabase REST\n(v_branch_metrics, etc.)"]
        D_MYSQL["MySQL (branch_reports)"]
        D_GROQ["Groq Chat Completions API"]
        D_CACHE["Supabase AI Cache Tables\n(ai_insights_cache, ai_*_narrative_cache, etc.)"]
    end

    %% =========================
    %% Jobs
    %% =========================
    subgraph JOBS["Background / CLI Jobs"]
        J_HEALTH["jobs/calculate_health.php"]
        J_CACHE["jobs/refresh_month_end_ai_cache.php"]
    end

    %% Browser -> Page Routes
    BROWSER --> PAGE_INDEX
    BROWSER --> PAGE_DETAIL
    BROWSER --> PAGE_HISTORY

    %% Entry files -> bootstrap/factory/controller
    PAGE_INDEX --> BOOT --> FACTORY --> C_DASH
    PAGE_DETAIL --> BOOT
    PAGE_HISTORY --> BOOT

    %% Page controller -> views
    C_DASH --> VIEW_INDEX
    C_DASH --> VIEW_DETAIL
    C_DASH --> VIEW_HISTORY
    C_DASH --> CONTROLLER_BASE
    C_DASH --> REQUEST

    %% Views -> JS
    VIEW_INDEX --> JS
    VIEW_DETAIL --> JS
    VIEW_HISTORY --> JS

    %% JS -> AJAX
    JS --> A_HEALTH_LIST
    JS --> A_HEALTH_DETAIL
    JS --> A_MONTHLY_HISTORY
    JS --> A_YEARLY_HISTORY
    JS --> A_AI_OVERVIEW
    JS --> A_AI_BRANCH
    JS --> A_AI_MONTHLY
    JS --> A_AI_YEARLY

    %% AJAX -> bootstrap/factory/api controllers
    A_HEALTH_LIST --> BOOT
    A_HEALTH_DETAIL --> BOOT
    A_MONTHLY_HISTORY --> BOOT
    A_YEARLY_HISTORY --> BOOT
    A_AI_OVERVIEW --> BOOT
    A_AI_BRANCH --> BOOT
    A_AI_MONTHLY --> BOOT
    A_AI_YEARLY --> BOOT

    FACTORY --> C_HEALTH
    FACTORY --> C_INSIGHTS
    FACTORY --> ENV
    FACTORY --> I_REPO

    %% API controllers -> services
    C_HEALTH --> S_HEALTH
    C_HEALTH --> CONTROLLER_BASE
    C_HEALTH --> REQUEST

    C_INSIGHTS --> S_AI
    C_INSIGHTS --> CONTROLLER_BASE
    C_INSIGHTS --> REQUEST

    %% Health service internals
    S_HEALTH --> S_SCORING
    S_HEALTH --> S_STATUS
    S_HEALTH --> S_INTERP
    S_HEALTH --> I_REPO

    %% AI service internals
    S_AI --> I_REPO
    S_AI --> S_HEALTH
    S_AI --> S_GROQ
    S_AI --> D_CACHE
    S_GROQ --> D_GROQ

    %% Repository resolution
    I_REPO --> R_CLICK
    I_REPO --> R_SUPA
    I_REPO --> R_MYSQL
    R_CLICK --> ENV
    R_SUPA --> ENV
    R_MYSQL --> ENV

    %% Repository -> external data
    R_CLICK --> D_CLICK
    R_SUPA --> D_SUPA
    R_MYSQL --> D_MYSQL

    %% Jobs wiring
    J_HEALTH --> BOOT
    J_HEALTH --> FACTORY
    J_HEALTH --> S_HEALTH

    J_CACHE --> BOOT
    J_CACHE --> FACTORY
    J_CACHE --> S_AI
    J_CACHE --> S_HEALTH
```

## 2) Route-to-Controller Map

```mermaid
flowchart TB
    subgraph PAGE_ROUTES["Page Routes"]
        P1["/index.php"] --> M1["DashboardController::index()"]
        P2["/detail.php?branch=..."] --> M2["DashboardController::detail()"]
        P3["/history.php?period=monthly|yearly&branch=..."] --> M3["DashboardController::history()"]
    end

    subgraph API_ROUTES["AJAX API Routes"]
        H1["/ajax/get_health_list.php"] --> HM1["HealthApiController::list()"]
        H2["/ajax/get_health_detail.php?branch=..."] --> HM2["HealthApiController::detail()"]
        H3["/ajax/get_monthly_comparison_history.php?branch=..."] --> HM3["HealthApiController::monthlyHistory()"]
        H4["/ajax/get_yearly_comparison_history.php?branch=..."] --> HM4["HealthApiController::yearlyHistory()"]

        I1["/ajax/get_ai_insights.php"] --> IM1["InsightsApiController::overview()"]
        I2["/ajax/get_ai_branch_interpretation.php?branch=..."] --> IM2["InsightsApiController::branchInterpretation()"]
        I3["/ajax/get_ai_monthly_narrative.php?month=YYYY-MM&branch=..."] --> IM3["InsightsApiController::monthlyNarrative()"]
        I4["/ajax/get_ai_yearly_narrative.php?year=YYYY&branch=..."] --> IM4["InsightsApiController::yearlyNarrative()"]
    end
```

## 3) Data Source Selection Logic

```mermaid
flowchart TD
    START["AppFactory::makeBranchRepository()"] --> SRC{"BRANCH_DATA_SOURCE"}
    SRC -->|clickhouse| C["ClickHouseBranchRepository"]
    SRC -->|supabase| S["SupabaseBranchRepository"]
    SRC -->|mysql/db/real| M["RealReportRepository::fromEnv()"]
    SRC -->|other| E["RuntimeException: Unsupported BRANCH_DATA_SOURCE"]

    C --> MODE{"CLICKHOUSE_SOURCE_MODE"}
    MODE -->|table| CT["Query configured table (e.g. branch_reports)"]
    MODE -->|dw_derived| CD["Run derived SQL over dw_sales_summary + dw_sales_report + dw_admin_cashier_report"]
```

## 4) Main Runtime Sequences

```mermaid
sequenceDiagram
    participant U as Browser/User
    participant P as Page (index/detail/history)
    participant J as franchise-health.js
    participant A as ajax/*.php route
    participant C as API Controller
    participant S as Service Layer
    participant R as Repository
    participant D as Data Source
    participant AI as Groq API
    participant Cache as Supabase AI Cache

    U->>P: Open page URL
    P->>J: Render HTML + load JS
    J->>A: GET JSON endpoint(s)
    A->>C: Dispatch via AppFactory + Request
    C->>S: Call health/insights methods
    S->>R: Fetch branch/month/year data
    R->>D: Query ClickHouse/Supabase/MySQL
    D-->>R: Raw rows
    R-->>S: Normalized branch rows

    alt Health endpoints
        S->>S: HealthScoring + Status/Interpretation mapping
    else AI endpoints
        S->>Cache: Read cached payload/data_hash
        alt Cache hit and reusable
            Cache-->>S: Cached AI payload
        else Cache miss/stale
            S->>AI: Prompt Groq (if configured)
            AI-->>S: AI response OR error
            S->>S: Validate + deterministic fallback when needed
            S->>Cache: Write refreshed payload
        end
    end

    S-->>C: Response payload
    C-->>A: JSON (Controller::json)
    A-->>J: API response
    J-->>U: Render cards/details/history/narratives/charts
```

