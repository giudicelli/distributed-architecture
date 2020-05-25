<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers\Remote;

use giudicelli\DistributedArchitecture\Master\Handlers\ProcessConfig;

/**
 * The config to start a remote process.
 *
 * @author Frédéric Giudicelli
 */
class Config extends ProcessConfig
{
    /** @var array<string> */
    private $hosts;

    private $username;

    private $privateKey;

    public function setHosts(array $hosts): self
    {
        if (empty($hosts)) {
            throw new \InvalidArgumentException('You cannot set an empty hosts list');
        }
        $this->hosts = $hosts;

        return $this;
    }

    public function getHosts(): array
    {
        return $this->hosts;
    }

    /**
     * Set the user name to connect with.
     */
    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Returns the user name to connect with.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Set the user private key to connect with.
     */
    public function setPrivateKey(?string $privateKey): self
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    /**
     * Returns the private key to connect with.
     */
    public function getPrivateKey(): ?string
    {
        return $this->privateKey;
    }
}
