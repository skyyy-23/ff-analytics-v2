<?php
require_once __DIR__ . '/../app/bootstrap.php';

$controller = AppFactory::makeHealthApiController();
$controller->monthlyHistory(new Request());
