<?php

namespace giudicelli\DistributedArchitecture\Master;

use Psr\Log\LoggerInterface;

/**
 * This interface defines the possible events associated with a LauncherInterface.
 *
 * @author Frédéric Giudicelli
 */
interface EventsInterface
{
    /**
     * Called when all the processes have been launched.
     *
     * @param LauncherInterface $launcher The launcher
     * @param LoggerInterface   $logger   A logger
     */
    public function started(LauncherInterface $launcher, LoggerInterface $logger): void;

    /**
     * Allows some checks to be performed.
     *
     * @param LauncherInterface $launcher The launcher
     * @param LoggerInterface   $logger   A logger
     */
    public function check(LauncherInterface $launcher, LoggerInterface $logger): void;

    /**
     * Called when all the processes are stopped.
     *
     * @param LauncherInterface $launcher The launcher
     * @param LoggerInterface   $logger   A logger
     */
    public function stopped(LauncherInterface $launcher, LoggerInterface $logger): void;

    /**
     * Called when a process is started.
     *
     * @param ProcessInterface $process The process
     * @param LoggerInterface  $logger  A logger
     */
    public function processStarted(ProcessInterface $process, LoggerInterface $logger): void;

    /**
     * Called when a process timed out.
     *
     * @param ProcessInterface $process The process
     * @param LoggerInterface  $logger  A logger
     */
    public function processTimedout(ProcessInterface $process, LoggerInterface $logger): void;

    /**
     * Called when a process stopped.
     *
     * @param ProcessInterface $process The process
     * @param LoggerInterface  $logger  A logger
     */
    public function processStopped(ProcessInterface $process, LoggerInterface $logger): void;

    /**
     * Called when a process returned data and is well alive.
     *
     * @param ProcessInterface $process The process
     * @param string           $line    Last line sent by the process
     * @param LoggerInterface  $logger  A logger
     */
    public function processWasSeen(ProcessInterface $process, string $line, LoggerInterface $logger): void;
}
