<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * This interface defines the possible events associated with a LauncherInterface.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
interface EventsInterface
{
    /**
     * Called when all the processes have been launched.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function started(LauncherInterface $launcher): void;

    /**
     * Allows some checks to be performed.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function check(LauncherInterface $launcher): void;

    /**
     * Called when all the processes are stopped.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function stopped(LauncherInterface $launcher): void;

    /**
     * Called when a process is started.
     *
     * @param ProcessInterface $process The process
     */
    public function processStarted(ProcessInterface $process): void;

    /**
     * Called when a process timed out.
     *
     * @param ProcessInterface $process The process
     */
    public function processTimedout(ProcessInterface $process): void;

    /**
     * Called when a process stopped.
     *
     * @param ProcessInterface $process The process
     */
    public function processStopped(ProcessInterface $process): void;

    /**
     * Called when a process returned data and is well alive.
     *
     * @param ProcessInterface $process The process
     */
    public function processWasSeen(ProcessInterface $process): void;
}
