<?php
require_once __DIR__ . '/../app/bootstrap.php';

$service = AppFactory::makeHealthService();

foreach ($service->getBranches() as $branch) {
    $health = $service->getBranchHealthDetailFormatted($branch['branch']);

    if (!$health) {
        continue;
    }

    echo $health['branch_name'] . ' => ' . $health['overall_score'] . PHP_EOL;

    // Later:
    // save to database
    // cache results
    // trigger alerts
}
