<?php

namespace giudicelli\DistributedArchitecture\Master;

use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;
use giudicelli\DistributedArchitecture\StoppableInterface;
use Psr\Log\LoggerInterface;

/**
 * The interface defines the model for a launcher. Its main role is to launch processes.
 *
 *  @author Frédéric Giudicelli
 */
interface LauncherInterface extends StoppableInterface
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
     * Set the events handler.
     *
     * @param EventsInterface $events An events interface to be called upon events
     */
    public function setEventsHandler(?EventsInterface $events): self;

    /**
     * Get the events handler.
     */
    public function getEventsHandler(): ?EventsInterface;

    /**
     * Return the logger interface.
     */
    public function getLogger(): LoggerInterface;

    /**
     * Set the group configs.
     *
     * @param array<GroupConfigInterface> $groupConfigs The configuration for each group of processes
     */
    public function setGroupConfigs(array $groupConfigs): self;

    /**
     * Run processes, this the main function when running on a master.
     *
     * @param bool $neverExit When set to true run will never exit upon the end of all processes, unless stop() is called. To start processes again you will need to call startGroup or startAll.
     */
    public function runMaster(bool $neverExit = false): void;

    /**
     * Run processes, this the main function when running on a remote.
     *
     * @param int $idStart      The current global id
     * @param int $groupIdStart The current group id
     * @param int $groupCount   The total number of processes in this group
     */
    public function runRemote(int $idStart, int $groupIdStart, int $groupCount): void;

    /**
     * Are there any process currently running?
     */
    public function isRunning(): bool;

    /**
     * Is the instance the master or a remote launcher?
     */
    public function isMaster(): bool;

    /**
     * Resume all processes in certain group. If some of the processes are already running they will be ignored.
     *
     * @param string $groupName The name of the group
     */
    public function resumeGroup(string $groupName): void;

    /**
     * Suspend all processes in certain group, but don't destroy them.
     *
     * @param string $groupName The name of the group
     * @param bool   $force     Set to true to force kill the processes
     */
    public function suspendGroup(string $groupName, bool $force = false): void;

    /**
     * Resume all processes. If some of the processes are already running they will be ignored.
     */
    public function resumeAll(): void;

    /**
     * Suspend all processes, but don't destroy them.
     *
     * @param bool $force Set to true to force kill the processes
     */
    public function suspendAll(bool $force = false): void;
}
