<?php
interface BranchRepositoryInterface
{
    public function getBranches(): array;

    public function findBranch(string $branchName): ?array;

    public function getMonthlyReports(?string $branchName = null, ?int $monthLimit = null): array;
}
