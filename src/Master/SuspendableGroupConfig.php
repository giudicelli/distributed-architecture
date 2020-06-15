<?php

namespace giudicelli\DistributedArchitecture\Master;

use giudicelli\DistributedArchitecture\Config\GroupConfig;
use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;

class SuspendableGroupConfig extends GroupConfig
{
    protected $suspended = false;

    public function __construct(?GroupConfigInterface $groupConfig = null)
    {
        if ($groupConfig) {
            $this->fromArray($groupConfig->toArray());
            $this->setProcessConfigs($groupConfig->getProcessConfigs());
        }
    }

    public function setSuspended(bool $suspended): self
    {
        $this->suspended = $suspended;

        return $this;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }
}
