<?php

namespace giudicelli\DistributedArchitecture\tests;

include 'vendor/autoload.php';

use giudicelli\DistributedArchitecture\Slave\Handler;
use Psr\Log\LoggerInterface;

if (empty($_SERVER['argv'][1])) {
    echo "Empty params\n";
    die();
}

$handler = new Handler($_SERVER['argv'][1]);
$handler->run(function (Handler $handler, LoggerInterface $logger) {
    $logger->emergency('ONE_LINE');
});
