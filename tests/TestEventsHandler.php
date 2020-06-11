<?php

namespace giudicelli\DistributedArchitecture\tests;

use giudicelli\DistributedArchitecture\Master\EventsInterface;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;
use Psr\Log\LoggerInterface;

/**
 * The implementation of EventsInterface.
 *
 * @author Frédéric Giudicelli
 */
class TestEventsHandler implements EventsInterface
{
    private $startedTime;

    private $stage = 1;

    /**
     * {@inheritdoc}
     */
    public function starting(LauncherInterface $launcher, LoggerInterface $logger): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function started(LauncherInterface $launcher, LoggerInterface $logger): void
    {
        if (!$launcher->isMaster()) {
            return;
        }
        $this->startedTime = time();
    }

    /**
     * {@inheritdoc}
     */
    public function check(LauncherInterface $launcher, LoggerInterface $logger): void
    {
        if (!$launcher->isMaster()) {
            return;
        }
        if (4 === $this->stage && (time() - $this->startedTime) > 40) {
            // We stop everyting after 40s
            $launcher->stop();
            $this->stage = 5;

            return;
        }
        if (3 === $this->stage && (time() - $this->startedTime) > 30) {
            // We restart test1 after 30s
            $launcher->runGroup('test1');
            $this->stage = 4;

            return;
        }
        if (2 === $this->stage && (time() - $this->startedTime) > 20) {
            // We stop test2 after 20s
            $launcher->stopGroup('test2');
            $this->stage = 3;

            return;
        }
        if (1 === $this->stage && (time() - $this->startedTime) > 10) {
            // We stop test1 after 10s
            $launcher->stopGroup('test1');
            $this->stage = 2;

            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopped(LauncherInterface $launcher, LoggerInterface $logger): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processStarted(ProcessInterface $process, LoggerInterface $logger): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processTimedout(ProcessInterface $process, LoggerInterface $logger): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processStopped(ProcessInterface $process, LoggerInterface $logger): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processWasSeen(ProcessInterface $process, string $line, LoggerInterface $logger): void
    {
    }
}
