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
}
