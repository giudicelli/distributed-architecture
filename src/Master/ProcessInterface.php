<?php

namespace giudicelli\DistributedArchitecture\Master;

use Psr\Log\LoggerInterface;

/**
 * The general interface for a started process.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
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
     * @param LoggerInterface      $logger       The logger, can be null
     * @param GroupConfigInterface $groupConfig  The group config
     * @param ConfigInterface      $config       The config
     * @param int                  $idStart      The current global id
     * @param int                  $groupIdStart The current group id
     *
     * @return array<ProcessInterface> The instanciated processes
     */
    public static function instanciate(?LoggerInterface $logger, GroupConfigInterface $groupConfig, ConfigInterface $config, int $idStart, int $groupIdStart): array;

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
     * Get the status of the process.
     *
     * @return string The status, one of STATUS_RUNNING, STATUS_STOPPED, STATUS_ERROR
     */
    public function getStatus(): string;

    /**
     * Get the number of times the process timedout.
     *
     * @return int The count
     */
    public function getTimeoutsCount(): int;
}
