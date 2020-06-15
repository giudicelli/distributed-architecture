<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * This interface defines the possible events associated with a LauncherInterface.
 *
 * @author Frédéric Giudicelli
 */
interface EventsInterface
{
    /**
     * Called before all the processes are launched, it will be called multiple times.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function starting(LauncherInterface $launcher): void;

    /**
     * Called when all the processes have been launched, it will be called multiple times.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function started(LauncherInterface $launcher): void;

    /**
     * Allows some checks to be performed, it will be called multiple times.
     *
     * @param LauncherInterface $launcher The launcher, it will be called multiplpe times
     */
    public function check(LauncherInterface $launcher): void;

    /**
     * Called when the launcher is done, it will be called only once.
     *
     * @param LauncherInterface $launcher The launcher
     */
    public function stopped(LauncherInterface $launcher): void;

    /**
     * Called when a process is created.
     *
     * @param ProcessInterface $process The process
     */
    public function processCreated(ProcessInterface $process): void;

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
     * @param string           $line    Last line sent by the process
     */
    public function processWasSeen(ProcessInterface $process, string $line): void;
}
