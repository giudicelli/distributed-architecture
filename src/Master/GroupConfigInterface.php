<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * The general group config interface.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
interface GroupConfigInterface extends ConfigInterface
{
    /**
     * Set the name of the group.
     */
    public function setName(string $name): GroupConfigInterface;

    /**
     * Returns the name of the group.
     */
    public function getName(): string;

    /**
     * Set the command to execute.
     */
    public function setCommand(string $command): GroupConfigInterface;

    /**
     * Returns the command to execute.
     */
    public function getCommand(): string;

    /**
     * Set the list of process configs.
     *
     * @param array<ProcessConfigInterface> $processes
     */
    public function setProcessConfigs(array $processConfigs): GroupConfigInterface;

    /**
     * Returns the list of process configs.
     *
     * @return array<ProcessConfigInterface>
     */
    public function getProcessConfigs(): array;

    /**
     * Sets the params to be passed to the processes.
     */
    public function setParams(?array $params): GroupConfigInterface;

    /**
     * Returns the params to be passed to the process.
     */
    public function getParams(): ?array;
}
