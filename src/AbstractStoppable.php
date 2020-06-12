<?php

namespace giudicelli\DistributedArchitecture;

/**
 * This an implementation of the StoppableInterface.
 *
 *  @author Frédéric Giudicelli
 */
abstract class AbstractStoppable implements StoppableInterface
{
    protected $lastSentPing = 0;

    protected $mustStop = false;

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->mustStop = true;
    }

    /**
     * {@inheritdoc}
     */
    public function mustStop(): bool
    {
        return $this->mustStop;
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): void
    {
        // Avoid flooding
        $t = time();
        if (($t - $this->lastSentPing) < 5) {
            return;
        }
        $this->lastSentPing = $t;

        echo self::PING_MESSAGE."\n";
        flush();
    }

    /**
     * {@inheritdoc}
     */
    public function sleep(int $s, bool $ping = true): bool
    {
        if ($this->mustStop()) {
            return false;
        }
        $t = time();
        while ((time() - $t) < $s) {
            if ($this->mustStop()) {
                return false;
            }
            usleep(30000);
            $this->ping();
        }
        $this->ping();

        return true;
    }
}
