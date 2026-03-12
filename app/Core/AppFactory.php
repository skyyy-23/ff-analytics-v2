<?php
// object creation for all, it builds services and controller.
class AppFactory
{
    private static $envLoaded = false;

    public static function makeBranchRepository(): BranchRepositoryInterface
    {
        self::loadEnv();
        $source = strtolower(trim(self::readEnv('BRANCH_DATA_SOURCE', 'clickhouse')));

        if ($source === 'clickhouse') {
            return new ClickHouseBranchRepository();
        }

        if ($source === 'supabase') {
            return new SupabaseBranchRepository();
        }

        if ($source === 'mysql' || $source === 'db' || $source === 'real') {
            return RealReportRepository::fromEnv(APP_BASE_PATH);
        }

        throw new RuntimeException(
            'Unsupported BRANCH_DATA_SOURCE "' . $source . '". Supported values: clickhouse, supabase, mysql.'
        );
    }

    public static function makeHealthService(?BranchRepositoryInterface $repository = null): FranchiseHealthService
    {
        $repo = $repository ?? self::makeBranchRepository();
        return new FranchiseHealthService($repo);
    }

    public static function makeInsightsService(?BranchRepositoryInterface $repository = null): AIInsightsService
    {
        $repo = $repository ?? self::makeBranchRepository();
        $healthService = self::makeHealthService($repo);

        return new AIInsightsService($repo, $healthService);
    }

    public static function makeDashboardController(): DashboardController
    {
        return new DashboardController();
    }

    public static function makeHealthApiController(): HealthApiController
    {
        return new HealthApiController(self::makeHealthService());
    }

    public static function makeInsightsApiController(): InsightsApiController
    {
        return new InsightsApiController(self::makeInsightsService());
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        Env::load(APP_BASE_PATH);
        self::$envLoaded = true;
    }

    private static function readEnv(string $key, string $default = ''): string
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
}
