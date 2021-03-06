<?php

namespace giudicelli\DistributedArchitecture\Slave;

use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;
use giudicelli\DistributedArchitecture\StoppableInterface;
use Psr\Log\LoggerInterface;

/**
 * The interface defines the model for an handler. Its main role is to handle commands send by the LauncherInterface, such as launching a list of processes or to kill them.
 *
 *  @author Frédéric Giudicelli
 */
interface HandlerInterface extends StoppableInterface
{
    /**
     * @return int The unique id of this process across all groups
     */
    public function getId(): int;

    /**
     * @return int The unique id of this process for the group it belongs to
     */
    public function getGroupId(): int;

    /**
     * @return int The total number of processes in the group it belongs to
     */
    public function getGroupCount(): int;

    /**
     * @return array The group config
     */
    public function getGroupConfig(): GroupConfigInterface;

    /**
     * @return LoggerInterface The logger to use
     */
    public function getLogger(): LoggerInterface;

    /**
     * Run this handler.
     *
     * @param callable $processCallback if we're not dealing with an internal command, this function will be called to handle the actual task
     */
    public function run(callable $processCallback): void;
}
