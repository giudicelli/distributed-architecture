<?php

namespace giudicelli\DistributedArchitecture\tests;

use giudicelli\DistributedArchitecture\Master\EventsInterface;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;

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
    public function starting(LauncherInterface $launcher): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function started(LauncherInterface $launcher): void
    {
        if (!$launcher->isMaster()) {
            return;
        }
        $this->startedTime = time();
    }

    /**
     * {@inheritdoc}
     */
    public function check(LauncherInterface $launcher): void
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
            $launcher->resumeGroup('test1');
            $this->stage = 4;

            return;
        }
        if (2 === $this->stage && (time() - $this->startedTime) > 20) {
            // We stop test2 after 20s
            $launcher->suspendGroup('test2');
            $this->stage = 3;

            return;
        }
        if (1 === $this->stage && (time() - $this->startedTime) > 10) {
            // We stop test1 after 10s
            $launcher->suspendGroup('test1');
            $this->stage = 2;

            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopped(LauncherInterface $launcher): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processStarted(ProcessInterface $process): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processTimedout(ProcessInterface $process): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processStopped(ProcessInterface $process): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processWasSeen(ProcessInterface $process, string $line): void
    {
    }
}
