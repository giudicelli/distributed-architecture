<?php

namespace giudicelli\DistributedArchitecture\Master;

/**
 * The general config interface.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
interface ConfigInterface extends \JsonSerializable
{
    /**
     * Load internal data from an array.
     */
    public function fromArray(array $config): void;

    /**
     * Convert internal data to an array.
     */
    public function toArray(): array;

    /**
     * Calculate a hash from the config.
     *
     * @return string The hash
     */
    public function getHash(): string;

    /**
     * Set the cwd of the command, to be set only if it differs from the master process'.
     */
    public function setPath(?string $path): self;

    /**
     * Returns the cwd of the command.
     */
    public function getPath(): ?string;

    /**
     * Set the path of the binary, to be set only if it differs from the master process'.
     */
    public function setBinPath(?string $binPath): self;

    /**
     * Returns the path of the binary.
     */
    public function getBinPath(): ?string;

    /**
     * Set the process' priority (range from -19, to 19).
     */
    public function setPriority(?int $priority): self;

    /**
     * Returns the process' priority.
     */
    public function getPriority(): ?int;

    /**
     * Set the process' timeout. A process is considered timeouted when we haven't received any data from it during a timeout period of time.
     */
    public function setTimeout(?int $timeout): self;

    /**
     * Returns the process' timeout.
     */
    public function getTimeout(): ?int;
}
