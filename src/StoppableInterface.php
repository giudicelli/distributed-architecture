<?php

namespace giudicelli\DistributedArchitecture;

/**
 * The interface defines the model for a stoppable object.
 *
 *  @author Frédéric Giudicelli
 */
interface StoppableInterface
{
    const PING_MESSAGE = 'Handler::ping';

    /**
     * Returns whether the process was asked to be stopped.
     *
     * @return bool The stop status
     */
    public function mustStop(): bool;

    /**
     * Sleep a certain time duration. Fail if process is requested to stop and send pings to the master during the wait time.
     *
     * @param int The wait time
     *
     * @return bool Was the wait interrupted by a stop signal
     */
    public function sleep(int $s): bool;

    /**
     * Pings the master process to let it know this current process is still alive.
     * It should be used when handling a rather long task, to avoid having the
     * master process think this process is dead.
     */
    public function ping(): void;

    /**
     * Stop.
     */
    public function stop(): void;
}
