<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers\Remote;

use giudicelli\DistributedArchitecture\Config\AbstractProcessConfig;

/**
 * The config to start a remote process.
 *
 * @author Frédéric Giudicelli
 */
class Config extends AbstractProcessConfig
{
    /** @var array<string> */
    protected $hosts;

    protected $username;

    protected $privateKey;

    /**
     * {@inheritdoc}
     */
    public function fromArray(array $config): void
    {
        parent::fromArray($config);

        if (!empty($config['hosts'])) {
            $this->setHosts($config['hosts']);
        }
        if (!empty($config['username'])) {
            $this->setUsername($config['username']);
        }
        if (!empty($config['privateKey'])) {
            $this->setPrivateKey($config['privateKey']);
        }
    }

    /**
     * Set the hosts to launch the process on.
     *
     * @param array $hosts the list of hosts
     */
    public function setHosts(array $hosts): self
    {
        if (empty($hosts)) {
            throw new \InvalidArgumentException('You cannot set an empty hosts list');
        }
        $this->hosts = $hosts;

        return $this;
    }

    /**
     * Get the the list of hosts to launch the process on.
     */
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
     * Set the user private key file to connect with.
     */
    public function setPrivateKey(?string $privateKey): self
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    /**
     * Returns the private key file to connect with.
     */
    public function getPrivateKey(): ?string
    {
        return $this->privateKey;
    }
}
