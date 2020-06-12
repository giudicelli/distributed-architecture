<?php

namespace giudicelli\DistributedArchitecture\Config;

/**
 * The group config.
 *
 * @author Frédéric Giudicelli
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

    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        // We never add the processes to data
        unset($array['processConfigs']);

        return $array;
    }

    /**
     * {@inheritdoc}
     */
    public function getHash(): string
    {
        $str = parent::getHash().'-'.$this->name.'-'.$this->command;
        if (!empty($this->params)) {
            $str .= '-'.json_encode($this->params);
        }

        return sha1($str);
    }

    /**
     * {@inheritdoc}
     */
    public function setName(string $name): GroupConfigInterface
    {
        $this->name = $name;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function setParams(?array $params): GroupConfigInterface
    {
        $this->params = $params;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /**
     * {@inheritdoc}
     */
    public function setCommand(string $command): GroupConfigInterface
    {
        $this->command = $command;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * {@inheritdoc}
     */
    public function setProcessConfigs(?array $processConfigs): GroupConfigInterface
    {
        $this->processConfigs = $processConfigs;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getProcessConfigs(): ?array
    {
        return $this->processConfigs;
    }
}
