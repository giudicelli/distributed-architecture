<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Master\ConfigInterface;

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
    protected $timeout = 300;

    public function getHash(): string
    {
        if (empty($this->path)) {
            return '';
        }

        return sha1($this->path);
    }

    public function fromArray(array $config): void
    {
        if (!empty($config['binPath'])) {
            $this->setBinPath($config['binPath']);
        }
        if (!empty($config['path'])) {
            $this->setPath($config['path']);
        }
        if (!empty($config['priority'])) {
            $this->setPriority($config['priority']);
        }
        if (!empty($config['timeout'])) {
            $this->setTimeout($config['timeout']);
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Set the cwd of the command, to be set only if it differs from the master process'.
     */
    public function setPath(?string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Returns the cwd of the command.
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Set the path of the binary, to be set only if it differs from the master process'.
     */
    public function setBinPath(?string $binPath): self
    {
        $this->binPath = $binPath;

        return $this;
    }

    /**
     * Returns the path of the binary.
     */
    public function getBinPath(): ?string
    {
        return $this->binPath;
    }

    /**
     * Set the process' priority (range from -19, to 19).
     */
    public function setPriority(?int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Returns the process' priority.
     */
    public function getPriority(): ?int
    {
        return $this->priority;
    }

    /**
     * Set the process' timeout. A process is considered timeouted when we haven't received any data from it during a timeout period of time.
     */
    public function setTimeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Returns the process' timeout.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }
}
