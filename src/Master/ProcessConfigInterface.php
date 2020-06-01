<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * The general config interface to start a process.
 *
 * @author Frédéric Giudicelli
 */
interface ProcessConfigInterface extends ConfigInterface
{
    /**
     * The number of instances to run.
     */
    public function setInstancesCount(int $instancesCount): ProcessConfigInterface;

    /**
     * Returns number of instances to run.
     */
    public function getInstancesCount(): int;
}
