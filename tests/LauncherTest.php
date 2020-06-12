<?php

declare(strict_types=1);

namespace giudicelli\DistributedArchitecture\tests;

use giudicelli\DistributedArchitecture\Config\GroupConfig;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Config as LocalConfig;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Process as LocalProcess;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Config as RemoteConfig;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Process as RemoteProcess;
use giudicelli\DistributedArchitecture\Master\Launcher;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once 'tests/TestEventsHandler.php';

/**
 * @internal
 * @coversNothing
 */
final class LauncherTest extends TestCase
{
    private $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger();
    }

    /**
     * @before
     */
    public function resetLogger()
    {
        $this->logger->reset();
    }

    /**
     * @group local
     */
    public function testLocalProcess(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SingleLine.php');

        $launcher = new Launcher(true, $this->logger);
        /** @var array<ProcessInterface> */
        $children = LocalProcess::instanciate($launcher, $groupConfig, $groupConfig->getProcessConfigs()[0], 1, 1, 1);
        $this->assertCount(1, $children, 'Instanciate a single local process');

        $this->assertTrue($children[0]->start(), 'Start process');

        sleep(1);

        $this->assertEquals(ProcessInterface::READ_SUCCESS, $children[0]->read(), 'LocalProcess::read returns READ_SUCCESS');

        sleep(1);
        $this->assertEquals(ProcessInterface::READ_FAILED, $children[0]->read(), 'LocalProcess::read returns READ_FAILED');

        $children[0]->stop();

        $output = $this->logger->getOutput();
        sort($output);

        $expected = [
            'emergency - [test] [localhost] [tests/SingleLine.php/1/1] ONE_LINE',
            'notice - [test] [localhost] [tests/SingleLine.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalOneInstance(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalTwoInstances(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->getProcessConfigs()[0]->setInstancesCount(2);

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test] [localhost] [tests/SlaveFile.php/2/2] Child 2 2',
            'info - [test] [localhost] [tests/SlaveFile.php/2/2] Child clean exit',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [localhost] [tests/SlaveFile.php/2/2] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalTwoGroupsOfOneInstance(): void
    {
        $groupConfigs = [
            $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php'),
            $this->buildLocalGroupConfig('test2', 'tests/SlaveFile.php'),
        ];

        $output = $this->runMaster($groupConfigs);

        $expected = [
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child 2 1',
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child clean exit',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [test2] [localhost] [tests/SlaveFile.php/2/1] Ended',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalPassingParameters(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['message' => 'New Message']);

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] New Message',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalMaxRunningTime(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['sleep' => 20]);

        $output = $this->runMaster([$groupConfig], 10);

        $expected = [
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [master] Stopping...',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalTimeoutForContent(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['forceSleep' => 90]);

        $output = $this->runMaster([$groupConfig], 60, 5);

        $expected = [
            'error - [master] Timeout waiting for content, force kill',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalTimeoutForCleanStop(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['neverDie' => true]);

        $output = $this->runMaster([$groupConfig], 5, 10);

        $expected = [
            'error - [master] Timeout waiting for clean shutdown, force kill',
            'info - [test] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'notice - [master] Stopping...',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group local
     */
    public function testLocalReadTimeout(): void
    {
        $groupConfig = $this->buildLocalGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig
            ->setParams(['neverDie' => true, 'message' => ''])
            ->setTimeout(2)
        ;

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'error - [test] [localhost] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'error - [test] [localhost] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'error - [test] [localhost] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [localhost] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group remote
     * @group mixed
     */
    public function testRemoteConnectivity(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SingleLine.php');

        $launcher = new Launcher(true, $this->logger);
        /** @var array<ProcessInterface> */
        $children = RemoteProcess::instanciate($launcher, $groupConfig, $groupConfig->getProcessConfigs()[0], 1, 1, 1);
        $this->assertCount(1, $children, 'Instanciate a single remote process');

        $this->assertTrue($children[0]->start(), 'Connect to 127.0.0.1');

        sleep(1);

        // PING
        $this->assertEquals(ProcessInterface::READ_SUCCESS, $children[0]->read(), 'RemoteProcess::read returns Master Ping');

        // ONE_LINE
        $this->assertEquals(ProcessInterface::READ_SUCCESS, $children[0]->read(), 'RemoteProcess::read returns Process ONE_LINE');

        // Process Ended
        $this->assertEquals(ProcessInterface::READ_SUCCESS, $children[0]->read(), 'RemoteProcess::read returns Process Ended');

        sleep(1);
        $this->assertEquals(ProcessInterface::READ_FAILED, $children[0]->read(), 'RemoteProcess::read returns READ_FAILED');

        $children[0]->stop();

        $output = $this->logger->getOutput();
        sort($output);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'emergency - [test] [127.0.0.1] [tests/SingleLine.php/1/1] ONE_LINE',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SingleLine.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteOneInstance(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteTwoInstances(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->getProcessConfigs()[0]->setInstancesCount(2);

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/2/2] Child 2 2',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/2/2] Child clean exit',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/2/2] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteTwoGroupsOfOneInstance(): void
    {
        $groupConfigs = [
            $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php'),
            $this->buildRemoteGroupConfig('test2', 'tests/SlaveFile.php'),
        ];

        $output = $this->runMaster($groupConfigs);

        $expected = [
            'debug - [test2] [127.0.0.1] Connected to host',
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test2] [127.0.0.1] [tests/SlaveFile.php/2/1] Child 2 1',
            'info - [test2] [127.0.0.1] [tests/SlaveFile.php/2/1] Child clean exit',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [test2] [127.0.0.1] Ended',
            'notice - [test2] [127.0.0.1] [tests/SlaveFile.php/2/1] Ended',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemotePassingParameters(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['message' => 'New Message']);

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] New Message',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteMaxRunningTime(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['sleep' => 20]);

        $output = $this->runMaster([$groupConfig], 10);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [master] Stopping...',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [master] Received SIGTERM, stopping',
            'notice - [test] [127.0.0.1] [master] Stopping...',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteTimeoutForContent(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['forceSleep' => 90]);

        $output = $this->runMaster([$groupConfig], 60, 5);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'error - [test] [127.0.0.1] [master] Timeout waiting for content, force kill',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteTimeoutForCleanStop(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig->setParams(['neverDie' => true]);

        $output = $this->runMaster([$groupConfig], 5, 10);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'debug - [test] [127.0.0.1] Connected to host',
            'error - [test] [127.0.0.1] [master] Timeout waiting for clean shutdown, force kill',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'notice - [master] Stopping...',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [master] Received SIGTERM, stopping',
            'notice - [test] [127.0.0.1] [master] Stopping...',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group remote
     */
    public function testRemoteReadTimeout(): void
    {
        $groupConfig = $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php');
        $groupConfig
            ->setParams(['neverDie' => true, 'message' => ''])
            ->setTimeout(2)
        ;

        $output = $this->runMaster([$groupConfig]);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'error - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'error - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'error - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Timeout reached while waiting for data...',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @depends testRemoteConnectivity
     * @group mixed
     */
    public function testMixedOneGroupTwoInstances(): void
    {
        $groupConfigs = [
            $this->buildRemoteGroupConfig('test', 'tests/SlaveFile.php'),
            $this->buildLocalGroupConfig('test2', 'tests/SlaveFile.php'),
        ];

        $output = $this->runMaster($groupConfigs);

        $expected = [
            'debug - [test] [127.0.0.1] Connected to host',
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child 2 1',
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child clean exit',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Child clean exit',
            'notice - [test2] [localhost] [tests/SlaveFile.php/2/1] Ended',
            'notice - [test] [127.0.0.1] Ended',
            'notice - [test] [127.0.0.1] [tests/SlaveFile.php/1/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    /**
     * @group neverexit
     */
    public function testNeverExit(): void
    {
        $groupConfigs = [
            $this->buildLocalGroupConfig('test1', 'tests/SlaveFile.php'),
            $this->buildLocalGroupConfig('test2', 'tests/SlaveFile.php'),
        ];
        $groupConfigs[0]->setParams(['sleep' => 2000]);
        $groupConfigs[1]->setParams(['sleep' => 2000]);

        $events = new TestEventsHandler();

        $master = new Launcher(true, $this->logger);

        $master
            ->setGroupConfigs($groupConfigs)
            ->setEventsHandler($events)
            ->runMaster(true)
        ;

        $output = $this->logger->getOutput();
        sort($output);

        print_r($output);

        $expected = [
            'info - [test1] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test1] [localhost] [tests/SlaveFile.php/1/1] Child 1 1',
            'info - [test1] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test1] [localhost] [tests/SlaveFile.php/1/1] Child clean exit',
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child 2 1',
            'info - [test2] [localhost] [tests/SlaveFile.php/2/1] Child clean exit',
            'notice - [master] Stopping...',
            'notice - [test1] [localhost] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test1] [localhost] [tests/SlaveFile.php/1/1] Ended',
            'notice - [test2] [localhost] [tests/SlaveFile.php/2/1] Ended',
        ];
        $this->assertEquals($expected, $output);
    }

    private function runMaster(array $groupConfigs, int $maxRunTime = 60, int $timeout = 300): array
    {
        $master = new Launcher(true, $this->logger);

        $master
            ->setMaxRunningTime($maxRunTime)
            ->setTimeout($timeout)
            ->setGroupConfigs($groupConfigs)
            ->runMaster()
        ;

        $output = $this->logger->getOutput();
        sort($output);

        return $output;
    }

    private function buildLocalGroupConfig(string $name, string $command, $count = 1): GroupConfig
    {
        $groupConfig = new GroupConfig();
        $groupConfig->setName($name);
        $groupConfig->setCommand($command);

        $processConfigs = [];
        for ($i = 0; $i < $count; ++$i) {
            $processConfigs[] = new LocalConfig();
        }
        $groupConfig->setProcessConfigs($processConfigs);

        return $groupConfig;
    }

    private function buildRemoteGroupConfig(string $name, string $command, $count = 1): GroupConfig
    {
        $groupConfig = new GroupConfig();
        $groupConfig->setName($name);
        $groupConfig->setCommand($command);

        $processConfigs = [];
        for ($i = 0; $i < $count; ++$i) {
            $processConfigs[] = (new RemoteConfig())->setHosts(['127.0.0.1']);
        }
        $groupConfig->setProcessConfigs($processConfigs);

        return $groupConfig;
    }
}

class Logger extends AbstractLogger
{
    private $output = [];

    public function reset()
    {
        $this->output = [];
    }

    public function log($level, $message, array $context = [])
    {
        foreach ($context as $key => $value) {
            $message = str_replace('{'.$key.'}', $value, $message);
        }
        $this->output[] = "{$level} - {$message}";
    }

    public function getOutput(): array
    {
        return $this->output;
    }
}
