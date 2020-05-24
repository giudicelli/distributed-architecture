<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;

/**
 * The group config.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
class GroupConfig extends AbstractConfig implements GroupConfigInterface
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $command;

    /** @var array */
    protected $params;

    /** @var array<ProcessConfig> */
    protected $processConfigs = [];

    public function fromArray(array $config): void
    {
        parent::fromArray($config);

        if (!empty($config['name'])) {
            $this->setName($config['name']);
        }
        if (!empty($config['params'])) {
            $this->setParams($config['params']);
        }
        if (!empty($config['command'])) {
            $this->setCommand($config['command']);
        }
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        // We never add the processes to data
        unset($array['processConfigs']);

        return $array;
    }

    public function getHash(): string
    {
        $str = parent::getHash().'-'.$this->name.'-'.$this->command;
        if (!empty($this->params)) {
            $str .= '-'.json_encode($this->params);
        }

        return sha1($str);
    }

    public function setName(string $name): GroupConfigInterface
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setParams(?array $params): GroupConfigInterface
    {
        $this->params = $params;

        return $this;
    }

    public function getParams(): ?array
    {
        return $this->params;
    }

    public function setCommand(string $command): GroupConfigInterface
    {
        $this->command = $command;

        return $this;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setProcessConfigs(?array $processConfigs): GroupConfigInterface
    {
        $this->processConfigs = $processConfigs;

        return $this;
    }

    public function getProcessConfigs(): array
    {
        return $this->processConfigs;
    }
}
