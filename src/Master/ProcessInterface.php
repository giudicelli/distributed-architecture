<?php

namespace giudicelli\DistributedArchitecture\Master;

use Psr\Log\LoggerInterface;

/**
 * The general interface for a started process.
 *
 * @author Frédéric Giudicelli
 */
interface ProcessInterface
{
    const STATUS_RUNNING = 'running';
    const STATUS_STOPPED = 'stopped';
    const STATUS_ERROR = 'error';

    const READ_SUCCESS = 1;
    const READ_EMPTY = 0;
    const READ_FAILED = -1;
    const READ_TIMEOUT = -2;

    /**
     * Get the config class associated with this process handler.
     *
     * @return string The config class
     */
    public static function getConfigClass(): string;

    /**
     * Instanciate the processes.
     *
     * @param LauncherInterface      $launcher     The parent launcher
     * @param EventsInterface        $events       An events interface to be called upon events, can be null
     * @param LoggerInterface        $logger       The logger, can be null
     * @param GroupConfigInterface   $groupConfig  The group config
     * @param ProcessConfigInterface $config       The process config
     * @param int                    $idStart      The current global id
     * @param int                    $groupIdStart The current group id
     * @param int                    $groupCount   The total number of processes in group
     *
     * @return array<ProcessInterface> The instanciated processes
     */
    public static function instanciate(LauncherInterface $launcher, ?EventsInterface $events, ?LoggerInterface $logger, GroupConfigInterface $groupConfig, ProcessConfigInterface $config, int $idStart, int $groupIdStart, int $groupCount): array;

    /**
     * Return the number of processes that will be started using a certain config.
     *
     * @param ConfigInterface $config The config
     *
     * @return int The number
     */
    public static function willStartCount(ConfigInterface $config): int;

    /**
     * Start the process.
     *
     * @return bool Success or failure
     */
    public function start(): bool;

    /**
     * Stop the process.
     *
     * @param int $signal The signal to send to the process to stop it
     */
    public function stop(int $signal = 0): void;

    /**
     * Perform a soft stop on the process.
     */
    public function softStop(): void;

    /**
     * Restart the process.
     *
     * @param int $signal The signal to send to the process to stop it
     *
     * @return bool Success or failure
     */
    public function restart(int $signal = 0): bool;

    /**
     * Perform a read on the process.
     *
     * @return int One of READ_SUCCESS, READ_EMPTY, READ_FAILED, READ_TIMEOUT
     */
    public function read(): int;

    /**
     * Get the display name for this process.
     *
     * @return string the display name
     */
    public function getDisplay(): string;

    /**
     * Get the unique id of this process across all groups.
     *
     * @return int The unique id
     */
    public function getId(): int;

    /**
     * Get the unique id of this process for the group it belongs to.
     *
     * @return int The unique id
     */
    public function getGroupId(): int;

    /**
     * Get the total number of started processes for the group this process belongs to.
     *
     * @return int The numbes of processes
     */
    public function getGroupCount(): int;

    /**
     * Get the status of the process.
     *
     * @return string The status, one of STATUS_RUNNING, STATUS_STOPPED, STATUS_ERROR
     */
    public function getStatus(): string;

    /**
     * Get the number of times in a row the process timedout.
     *
     * @return int The count
     */
    public function getTimeoutsCount(): int;

    /**
     * Return the timestamp of the last time we received data from this process.
     *
     * @return int The timestamp
     */
    public function getLastSeen(): int;

    /**
     * Return the parent launcher.
     *
     * @return LauncherInterca The parent launcher
     */
    public function getParent(): LauncherInterface;

    /**
     * Return the group config.
     *
     * @return GroupConfigInterface The group config
     */
    public function getGroupConfig(): GroupConfigInterface;

    /**
     * Return the host.
     *
     * @return string The host
     */
    public function getHost(): string;
}
