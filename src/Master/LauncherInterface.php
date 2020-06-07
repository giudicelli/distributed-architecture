<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * The interface defines the model for a launcher. Its main role is to launch processes.
 *
 *  @author Frédéric Giudicelli
 */
interface LauncherInterface
{
    /**
     * Set the general timeout.
     */
    public function setTimeout(?int $timeout): self;

    /**
     * Get the general timeout.
     */
    public function getTimeout(): ?int;

    /**
     * Set the maximum time it can run for.
     */
    public function setMaxRunningTime(?int $maxRunningTime): self;

    /**
     * Set the maximum number of times a process can timeout before it is considered dead and restarted. Default is 3.
     */
    public function setMaxProcessTimeout(?int $maxProcessTimeout): self;

    /**
     * Run processes.
     *
     * @param array<GroupConfigInterface> $groupConfigs The configuration for each group of processes
     * @param EventsInterface             $events       An events interface to be called upon events
     * @param bool                        $neverExit    When set to true run will never exit upon the end of all processes, unless stop() is called. To start processes again you will need to call startGroup or startAll.
     */
    public function run(array $groupConfigs, EventsInterface $events = null, bool $neverExit = false): void;

    /**
     * Run a single process.
     *
     * @param GroupConfigInterface   $groupConfig   The configuration for the group
     * @param ProcessConfigInterface $processConfig The configuration for the process
     * @param int                    $idStart       The current global id
     * @param int                    $groupIdStart  The current group id
     * @param int                    $groupCount    The total number of processes in this group
     * @param EventsInterface        $events        An events interface to be called upon events
     */
    public function runSingle(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart, int $groupCount, EventsInterface $events = null): void;

    /**
     * Stop all processes. Even if $neverExit was set to true when run() was called.
     */
    public function stop(): void;

    /**
     * Are there any process currently running?
     */
    public function isRunning(): bool;

    /**
     * Is the instance the master or a remote launcher?
     */
    public function isMaster(): bool;

    /**
     * Start all processes in certain group. If some of the processes are already running they will be ignored. It needs to be used in conjonction with $neverExit = true on run();.
     *
     * @param string $groupName The name of the group
     */
    public function runGroup(string $groupName): void;

    /**
     * Stop all processes in certain group. It needs to be used in conjonction with $neverExit = true on run();.
     *
     * @param string $groupName The name of the group
     * @param bool   $force     Set to true to force kill the processes
     */
    public function stopGroup(string $groupName, bool $force = false): void;

    /**
     * Start all processes. If some of the processes are already running they will be ignored. It needs to be used in conjonction with $neverExit = true on run();.
     */
    public function runAll(): void;

    /**
     * Stop all processes. If some of the processes are already running they will be ignored. It needs to be used in conjonction with $neverExit = true on run();.
     *
     * @param bool $force Set to true to force kill the processes
     */
    public function stopAll(bool $force = false): void;
}
