<?php

namespace giudicelli\DistributedArchitecture\tests;

include 'vendor/autoload.php';

use giudicelli\DistributedArchitecture\Slave\Handler;
use giudicelli\DistributedArchitecture\Slave\HandlerInterface;

if (empty($_SERVER['argv'][1])) {
    echo "Empty params\n";
    die();
}

$handler = new Handler($_SERVER['argv'][1]);
$handler->run(function (HandlerInterface $handler) {
    $handler->getLogger()->emergency('ONE_LINE');
});
