<?php
class HealthApiController extends Controller
{
    private $service;

    public function __construct(FranchiseHealthService $service)
    {
        $this->service = $service;
    }

    public function list(Request $request): void
    {
        $this->json($this->service->getBranchHealthListFormatted());
    }

    public function detail(Request $request): void
    {
        $branchName = $request->query('branch');
        if (!$branchName) {
            $this->json(['error' => 'Branch required']);
            return;
        }

        $data = $this->service->getBranchHealthDetailFormatted((string)$branchName);
        if (!$data) {
            $this->json(['error' => 'Branch not found']);
            return;
        }

        $this->json($data);
    }

    public function monthlyHistory(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $branchName = trim((string)$request->query('branch', ''));
        $this->json($this->service->getMonthlyComparisonHistoryFormatted($branchName), 200, $headers);
    }

    public function yearlyHistory(Request $request): void
    {
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $branchName = trim((string)$request->query('branch', ''));
        $this->json($this->service->getYearlyComparisonHistoryFormatted($branchName), 200, $headers);
    }
}
