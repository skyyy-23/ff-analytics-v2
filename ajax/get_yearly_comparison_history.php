<?php
require_once __DIR__ . '/../app/bootstrap.php';

$controller = AppFactory::makeHealthApiController();
$controller->yearlyHistory(new Request());
