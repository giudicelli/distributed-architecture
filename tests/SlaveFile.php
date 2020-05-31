<?php

namespace giudicelli\DistributedArchitecture\tests;

include 'vendor/autoload.php';

use giudicelli\DistributedArchitecture\Slave\Handler;

if (empty($_SERVER['argv'][1])) {
    echo "Empty params\n";
    die();
}

$handler = new Handler($_SERVER['argv'][1]);
$handler->run(function (Handler $handler) {
    $groupConfig = $handler->getGroupConfig();

    $params = $groupConfig->getParams();

    if (isset($params['message'])) {
        echo $params['message']."\n";
    } else {
        echo "Child {$handler->getId()} {$handler->getGroupId()} \n";
    }
    flush();

    if (!empty($params['sleep'])) {
        $handler->sleep($params['sleep']);
    } elseif (!empty($params['forceSleep'])) {
        sleep($params['forceSleep']);
    } elseif (!empty($params['neverDie'])) {
        for (;;) {
            sleep(1);
        }
    }

    echo "Child clean exit\n";
    flush();
});
