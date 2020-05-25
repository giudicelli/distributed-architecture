
# distributed-architecture

PHP Distributed Architecture is a library meant to be helping managing a distributed architecture. It's sole purpose is to start processes on the local or remotes servers.

## Installation

```bash
$ composer require giudicelli/distributed-architecture
```

## Using

To run your distributed architecture you will mainly need to use two classes Master\Launcher and Slave\Handler.

### Master process

Here is a simple example to start the master process.

```php
use giudicelli\DistributedArchitecture\Master\Handlers\GroupConfig;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Config as LocalConfig;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Config as RemoteConfig;
use giudicelli\DistributedArchitecture\Master\Launcher;
use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    public function log($level, $message, array $context = [])
    {
        foreach ($context as $key => $value) {
            $message = str_replace('{'.$key.'}', $value, $message);
        }
        echo "{$level} - {$message}\n";
        flush();
    }
}

$logger = new Logger();

$groupConfigs = [
    (new GroupConfig())
        ->setName('First Group')
        ->setCommand('script1.php')
        ->setProcessConfigs([
            (new LocalConfig())
                ->setInstancesCount(3),
            (new RemoteConfig())
                ->setHosts(['remote-server-1', 'remote-server-2'])
                ->setInstancesCount(2),
        ]),
    (new GroupConfig())
        ->setName('Second Group')
        ->setCommand('script2.php')
        ->setProcessConfigs([
            (new LocalConfig())
                ->setInstancesCount(2),
            (new RemoteConfig())
                ->setHosts(['remote-server-1', 'remote-server-2'])
                ->setInstancesCount(2),
        ]),
];
$master = new Launcher($logger);
$master->run($groupConfigs);
```

The above code creates two groups.

One group is called "First Group" and it will run "script1.php" :
- 3 instances on the local machine,
- 2 instances on the "remote-server1" machine,
- 2 instances on the "remote-server2" machine.

A total of 7 instances of "script1.php" will run.


The other group is called "Second Group" and it will run "script2.php" :
- 2 instances on the local machine,
- 2 instances on the "remote-server1" machine,
- 2 instances on the "remote-server2" machine.

A total of 6 instances of "script2.php" will run.

### Slave process

A slave process must use the "Slave\Handler" class, as the master may be sending commands that need to handled. It also allows you're script to do a clean exit upon the master's request. Using the above example, here is an example of an implementation for "script1.php" or "script1.php".

```php
use giudicelli\DistributedArchitecture\Slave\Handler;

if (empty($_SERVER['argv'][1])) {
    echo "Empty params\n";
    die(1);
}

$handler = new Handler($_SERVER['argv'][1]);
$handler->run(function (Handler $handler) {
    $groupConfig = $handler->getGroupConfig();

    $params = $groupConfig->getParams();

    echo $params['message']."\n";

    while(!$handler->mustStop()) {
        // Do a very long task
        // ...

        // Let master know we're still running
        $handler->sendPing();
    }
});

```