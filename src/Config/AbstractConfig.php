<?php

namespace giudicelli\DistributedArchitecture\Config;

/**
 * The general config, shared between a group config and process config. When used for a process config, it allows to overide the default values of the group.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
abstract class AbstractConfig implements ConfigInterface
{
    /** @var string */
    protected $binPath;

    /** @var string */
    protected $path;

    /** @var int */
    protected $priority;

    /** @var int */
    protected $timeout = -1;

    /**
     * {@inheritdoc}
     */
    public function getHash(): string
    {
        if (empty($this->path)) {
            return '';
        }

        return sha1($this->path);
    }

    /**
     * {@inheritdoc}
     */
    public function fromArray(array $config): void
    {
        if (!empty($config['binPath'])) {
            $this->setBinPath($config['binPath']);
        }
        if (!empty($config['path'])) {
            $this->setPath($config['path']);
        }
        if (isset($config['priority'])) {
            $this->setPriority($config['priority']);
        }
        if (isset($config['timeout'])) {
            $this->setTimeout($config['timeout']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function setPath(?string $path): ConfigInterface
    {
        $this->path = $path;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function setBinPath(?string $binPath): ConfigInterface
    {
        $this->binPath = $binPath;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPath(): ?string
    {
        return $this->binPath;
    }

    /**
     * {@inheritdoc}
     */
    public function setPriority(?int $priority): ConfigInterface
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): ?int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function setTimeout(?int $timeout): ConfigInterface
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }
}
