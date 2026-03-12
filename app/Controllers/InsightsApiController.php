<?php
class InsightsApiController extends Controller
{
    private $service;

    public function __construct(AIInsightsService $service)
    {
        $this->service = $service;
    }

    public function overview(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        try {
            $data = $this->service->getOverviewInsights();
            $this->json($data, 200, $headers);
        } catch (Exception $e) {
            $this->json([
                'summary' => 'Error: ' . $e->getMessage(),
                'recommendations' => [],
                'source' => 'fallback',
            ], 500, $headers);
        }
    }

    public function branchInterpretation(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $branchName = trim((string)$request->query('branch', ''));
        if ($branchName === '') {
            $this->json([
                'interpretation' => [],
                'source' => 'fallback',
                'error' => 'Missing branch parameter.',
            ], 400, $headers);
            return;
        }

        try {
            $data = $this->service->getBranchInterpretation($branchName);
            $this->json($data, 200, $headers);
        } catch (Exception $e) {
            $this->json([
                'interpretation' => [],
                'source' => 'fallback',
                'error' => 'Error: ' . $e->getMessage(),
            ], 500, $headers);
        }
    }

    public function monthlyNarrative(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $monthKey = trim((string)$request->query('month', ''));
        $branchName = trim((string)$request->query('branch', ''));
        if ($monthKey === '') {
            $this->json([
                'narrative' => [],
                'error' => 'Missing month parameter.',
            ], 400, $headers);
            return;
        }

        try {
            $data = $this->service->getMonthlyNarrative($monthKey, $branchName);
            $this->json($data, 200, $headers);
        } catch (Exception $e) {
            $this->json([
                'narrative' => [],
                'error' => 'Error: ' . $e->getMessage(),
            ], 500, $headers);
        }
    }

    public function yearlyNarrative(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $yearKey = trim((string)$request->query('year', ''));
        $branchName = trim((string)$request->query('branch', ''));
        if ($yearKey === '') {
            $this->json([
                'narrative' => [],
                'error' => 'Missing year parameter.',
            ], 400, $headers);
            return;
        }

        try {
            $data = $this->service->getYearlyNarrative($yearKey, $branchName);
            $this->json($data, 200, $headers);
        } catch (Exception $e) {
            $this->json([
                'narrative' => [],
                'error' => 'Error: ' . $e->getMessage(),
            ], 500, $headers);
        }
    }
}
