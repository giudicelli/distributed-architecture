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
     * Stop.
     */
    public function stop(): void;

    /**
     * Set the general timeout.
     */
    public function setTimeout(?int $timeout): self;

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
     */
    public function run(array $groupConfigs): void;

    /**
     * Run a single process.
     *
     * @param GroupConfigInterface   $groupConfig   The configuration for the group
     * @param ProcessConfigInterface $processConfig The configuration for the process
     * @param int                    $idStart       The current global id
     * @param int                    $groupIdStart  The current group id
     * @param int                    $groupCount    The total number of processes in this group
     */
    public function runSingle(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart, int $groupCount): void;
}
