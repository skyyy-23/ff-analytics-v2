<?php
require_once __DIR__ . '/app/bootstrap.php';

$controller = AppFactory::makeDashboardController();
$controller->index(new Request());
