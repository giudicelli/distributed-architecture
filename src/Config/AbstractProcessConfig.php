<?php

namespace giudicelli\DistributedArchitecture\Config;

/**
 * The general config to start a process.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
abstract class AbstractProcessConfig extends AbstractConfig implements ProcessConfigInterface
{
    /** @var int */
    protected $instancesCount = 1;

    /**
     * {@inheritdoc}
     */
    public function fromArray(array $config): void
    {
        parent::fromArray($config);

        if (isset($config['instancesCount'])) {
            $this->setInstancesCount($config['instancesCount']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setInstancesCount(int $instancesCount): ProcessConfigInterface
    {
        if (!$instancesCount) {
            throw new \InvalidArgumentException('You cannot set an empty instances count');
        }
        $this->instancesCount = $instancesCount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getInstancesCount(): int
    {
        return $this->instancesCount;
    }
}
