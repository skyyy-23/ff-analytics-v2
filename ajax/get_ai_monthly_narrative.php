<?php
require_once __DIR__ . '/../app/bootstrap.php';

$controller = AppFactory::makeInsightsApiController();
$controller->monthlyNarrative(new Request());
