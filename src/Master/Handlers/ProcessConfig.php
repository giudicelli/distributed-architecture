<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;

/**
 * The general config to start a process.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
class ProcessConfig extends AbstractConfig implements ProcessConfigInterface
{
    /** @var int */
    protected $instancesCount = 1;

    public function fromArray(array $config): void
    {
        parent::fromArray($config);

        if (!empty($config['instancesCount'])) {
            $this->setInstancesCount($config['instancesCount']);
        }
    }

    public function setInstancesCount(int $instancesCount): ProcessConfigInterface
    {
        $this->instancesCount = $instancesCount;

        return $this;
    }

    public function getInstancesCount(): int
    {
        return $this->instancesCount;
    }
}
