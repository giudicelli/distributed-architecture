<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Master\EventsInterface;
use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;
use giudicelli\DistributedArchitecture\Slave\Handler;
use Psr\Log\LoggerInterface;

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
    protected $lastSeenTimeout = 0;
    protected $id = 0;
    protected $groupId = 0;
    protected $groupCount = 0;
    protected $timeoutsCount = 0;
    protected $logger;
    protected $host = 'localhost';

    /** @var ProcessConfigInterface */
    protected $config;

    /** @var GroupConfigInterface */
    protected $groupConfig;

    /** @var LauncherInterface */
    protected $launcher;

    /** @var null|EventsInterface */
    protected $events;

    public function __construct(
        LoggerInterface $logger,
        int $id,
        int $groupId,
        int $groupCount,
        GroupConfigInterface $groupConfig,
        ProcessConfigInterface $config,
        LauncherInterface $launcher,
        ?EventsInterface $events = null
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
        $this->events = $events;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        if (!$this->run()) {
            $this->status = self::STATUS_ERROR;

            return false;
        }
        $this->lastSeen = $this->lastSeenTimeout = time();
        $this->status = self::STATUS_RUNNING;

        if ($this->isEventCompatible() && $this->events) {
            $this->events->processStarted($this);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(int $signal = 0): void
    {
        $this->kill($signal);
        if (self::STATUS_STOPPED !== $this->status) {
            $this->status = self::STATUS_STOPPED;
            $this->logMessage('notice', 'Ended');
            if ($this->isEventCompatible() && $this->events) {
                $this->events->processStopped($this);
            }
        }
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
        $line = '';
        $status = $this->readLine($line);
        switch ($status) {
            case self::READ_SUCCESS:
                $this->timeoutsCount = 0;
                $this->lastSeen = $this->lastSeenTimeout = time();

                if ($this->isEventCompatible() && $this->events) {
                    $this->events->processWasSeen($this, $line);
                }

                if (Handler::ENDED_MESSAGE === $line) {
                    // The child procces is exiting
                    return self::READ_FAILED;
                }

                if (Handler::PING_MESSAGE === $line) {
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

                    if ($this->isEventCompatible() && $this->events) {
                        $this->events->processTimedout($this);
                    }

                    return self::READ_TIMEOUT;
                }

                return $status;
            case self::READ_FAILED:
                $this->status = self::STATUS_ERROR;

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
        $this->logger->log($level, $message, $context);
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

    /** Return the configured timeout for this process */
    protected function getTimeout(): int
    {
        if (-1 !== $this->config->getTimeout()) {
            return $this->config->getTimeout();
        }
        if (-1 !== $this->groupConfig->getTimeout()) {
            return $this->groupConfig->getTimeout();
        }

        return 30;
    }
}
