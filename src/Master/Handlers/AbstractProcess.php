<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Config\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;
use giudicelli\DistributedArchitecture\Slave\Handler;
use giudicelli\DistributedArchitecture\StoppableInterface;

/**
 * The general implementation for a process.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
abstract class AbstractProcess implements ProcessInterface
{
    protected $status = self::STATUS_STOPPED;
    protected $lastSeen = 0;
    protected $stoppingAt = 0;
    protected $lastSeenTimeout = 0;
    protected $id = 0;
    protected $groupId = 0;
    protected $groupCount = 0;
    protected $timeoutsCount = 0;
    protected $host = 'localhost';

    /** @var ProcessConfigInterface */
    protected $config;

    /** @var GroupConfigInterface */
    protected $groupConfig;

    /** @var LauncherInterface */
    protected $launcher;

    public function __construct(
        int $id,
        int $groupId,
        int $groupCount,
        GroupConfigInterface $groupConfig,
        ProcessConfigInterface $config,
        LauncherInterface $launcher
    ) {
        if (!$groupConfig->getCommand()) {
            throw new \InvalidArgumentException('Missing command for: '.json_encode($groupConfig));
        }

        $this->id = $id;
        $this->groupId = $groupId;
        $this->groupCount = $groupCount;
        $this->groupConfig = $groupConfig;
        $this->config = $config;
        $this->launcher = $launcher;

        if ($this->isEventCompatible() && $this->getParent()->getEventsHandler()) {
            $this->getParent()->getEventsHandler()->processCreated($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        // We're asked to start a process that
        // was previously stopping, we need to
        // force kill it
        if (self::STATUS_STOPPING === $this->status) {
            $this->stop(SIGKILL);
        } elseif ($this->isRunning()) {
            // Already running, ignore
            return true;
        }

        if (!$this->run()) {
            $this->status = self::STATUS_ERROR;

            return false;
        }
        $this->stoppingAt = 0;
        $this->lastSeen = $this->lastSeenTimeout = time();
        $this->status = self::STATUS_RUNNING;

        if ($this->isEventCompatible() && $this->getParent()->getEventsHandler()) {
            $this->getParent()->getEventsHandler()->processStarted($this);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(int $signal = 0): void
    {
        // Not running, ignore
        if ($this->isRunning()) {
            $this->kill($signal);
        }

        if (self::STATUS_STOPPED !== $this->status) {
            $this->status = self::STATUS_STOPPED;
            $this->logMessage('notice', 'Ended');
            if ($this->isEventCompatible() && $this->getParent()->getEventsHandler()) {
                $this->getParent()->getEventsHandler()->processStopped($this);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function softStop(): void
    {
        // Not running, ignore
        if (!$this->isRunning()) {
            return;
        }

        $this->sendSignal(SIGTERM);
        $this->status = self::STATUS_STOPPING;
        $this->stoppingAt = time();
    }

    /**
     * {@inheritdoc}
     */
    public function restart(int $signal = 0): bool
    {
        $this->stop($signal);

        return $this->start();
    }

    /**
     * {@inheritdoc}
     */
    public function read(): int
    {
        if (!$this->isRunning()) {
            return self::READ_FAILED;
        }

        // Check for softStop timeout
        if (ProcessInterface::STATUS_STOPPING === $this->getStatus()) {
            $timeout = $this->getTimeout();
            if ($timeout && (time() - $this->stoppingAt) >= $timeout) {
                $this->logMessage('error', 'Timeout reached while waiting for soft stop...');

                $this->stop(SIGKILL);

                return self::READ_FAILED;
            }
        }

        $line = '';
        $status = $this->readLine($line);
        switch ($status) {
            case self::READ_SUCCESS:
                $this->timeoutsCount = 0;
                $this->lastSeen = $this->lastSeenTimeout = time();

                if ($this->isEventCompatible() && $this->getParent()->getEventsHandler()) {
                    $this->getParent()->getEventsHandler()->processWasSeen($this, $line);
                }

                if (Handler::ENDED_MESSAGE === $line) {
                    // The child procces is exiting
                    return self::READ_FAILED;
                }

                if (StoppableInterface::PING_MESSAGE === $line) {
                    return self::READ_SUCCESS;
                }

                $this->logMessage('info', $line);

                return $status;
            case self::READ_EMPTY:
                $timeout = $this->getTimeout();
                if ($timeout && (time() - $this->lastSeenTimeout) >= $timeout) {
                    ++$this->timeoutsCount;
                    $this->lastSeenTimeout = time();
                    $this->logMessage('error', 'Timeout reached while waiting for data...');

                    if ($this->isEventCompatible() && $this->getParent()->getEventsHandler()) {
                        $this->getParent()->getEventsHandler()->processTimedout($this);
                    }

                    return self::READ_TIMEOUT;
                }

                return $status;
            case self::READ_FAILED:
                return $status;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getGroupId(): int
    {
        return $this->groupId;
    }

    /**
     * {@inheritdoc}
     */
    public function getGroupCount(): int
    {
        return $this->groupCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeoutsCount(): int
    {
        return $this->timeoutsCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastSeen(): int
    {
        return $this->lastSeen;
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): LauncherInterface
    {
        return $this->launcher;
    }

    /**
     * {@inheritdoc}
     */
    public function getDisplay(): string
    {
        return $this->groupConfig->getCommand().'/'.$this->id.'/'.$this->groupId;
    }

    /**
     * {@inheritdoc}
     */
    public function getGroupConfig(): GroupConfigInterface
    {
        return $this->groupConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeout(): int
    {
        if (-1 !== $this->config->getTimeout()) {
            return $this->config->getTimeout();
        }
        if (-1 !== $this->groupConfig->getTimeout()) {
            return $this->groupConfig->getTimeout();
        }

        return 30;
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        return self::STATUS_STOPPED !== $this->status && self::STATUS_ERROR !== $this->status;
    }

    /**
     * Do the actual start.
     */
    abstract protected function run(): bool;

    /**
     * Kill the process and clean up.
     *
     * @param int $signal The signal to send the process to kill it
     */
    abstract protected function kill(int $signal = 0): void;

    /**
     * Send a signal to the process.
     *
     * @param int $signal the signal to send
     */
    abstract protected function sendSignal(int $signal): void;

    /**
     * Read one line from the process.
     *
     * @param string $line The line that was read
     *
     * @return int One of READ_SUCCESS, READ_EMPTY, READ_FAILED
     */
    abstract protected function readLine(string &$line): int;

    /**
     * Return if the implementation should be handled with EventsInterface.
     *
     * @return bool true if it's compatible, else false
     */
    abstract protected function isEventCompatible(): bool;

    /**
     * Log one message.
     *
     * @param string  $level   The level of the log (emergency, alert, critical, error, warning, notice, info, debug)
     * @param string  $message The message to log
     * @param mixed[] $context The context of the message
     */
    protected function logMessage(string $level, string $message, array $context = []): void
    {
        $context['display'] = $this->getDisplay();
        $context['host'] = $this->getHost();
        $context['group'] = $this->getGroupConfig()->getName();
        $this->getParent()->getLogger()->log($level, $message, $context);
    }

    /**
     * Build the mandatory parameters that need to be passed to a process.
     *
     * @return array The parameters
     */
    protected function buildParams(): array
    {
        return [
            Handler::PARAM_ID => $this->getId(),
            Handler::PARAM_GROUP_ID => $this->getGroupId(),
            Handler::PARAM_GROUP_COUNT => $this->getGroupCount(),
            Handler::PARAM_GROUP_CONFIG => $this->groupConfig,
            Handler::PARAM_GROUP_CONFIG_CLASS => get_class($this->groupConfig),
        ];
    }

    /**
     * Return the basic shell command to execute this process.
     *
     * @param $params the params to pass to the command
     *
     * @return string the shell command
     */
    protected function getShellCommand(array $params): string
    {
        $params = escapeshellarg(json_encode($params));

        return $this->getBinPath().' '.$this->groupConfig->getCommand().' '.$params;
    }

    /**
     * Return the value of the binary to execute.
     */
    protected function getBinPath(): string
    {
        if ($this->config->getBinPath()) {
            return $this->config->getBinPath();
        }
        if ($this->groupConfig->getBinPath()) {
            return $this->groupConfig->getBinPath();
        }

        return PHP_BINARY;
    }
}
