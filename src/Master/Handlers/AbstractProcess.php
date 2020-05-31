<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;
use giudicelli\DistributedArchitecture\Slave\Handler;
use Psr\Log\LoggerInterface;

/**
 * The general model for a process.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
abstract class AbstractProcess implements ProcessInterface
{
    protected $display = '';
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

    public function __construct(
        int $id,
        int $groupId,
        int $groupCount,
        GroupConfigInterface $groupConfig,
        ProcessConfigInterface $config,
        LoggerInterface $logger = null
    ) {
        if (!$groupConfig->getCommand()) {
            throw new \InvalidArgumentException('Missing command for: '.json_encode($groupConfig));
        }

        $this->id = $id;
        $this->groupId = $groupId;
        $this->groupCount = $groupCount;
        $this->groupConfig = $groupConfig;
        $this->config = $config;
        $this->logger = $logger;
        $this->display = $this->getDisplay();
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

                if (Handler::ENDED_MESSAGE === $line) {
                    // The child procces is exiting
                    $this->status = self::STATUS_STOPPED;
                    $this->logMessage('notice', 'Ended');

                    return self::READ_SUCCESS;
                }
                if (Handler::PING_MESSAGE === $line) {
                    return self::READ_SUCCESS;
                }

                $matches = [];
                if (preg_match('/^[{]level:([a-z]+)[}]/', $line, $matches)) {
                    $this->logMessage($matches[1], substr($line, strlen($matches[0])));
                } else {
                    $this->logMessage('info', $line);
                }

                return $status;
            case self::READ_EMPTY:
                $timeout = $this->getTimeout();
                if ($timeout && (time() - $this->lastSeenTimeout) >= $timeout) {
                    ++$this->timeoutsCount;
                    $this->lastSeenTimeout = time();
                    $this->logMessage('error', 'Timeout reached while waiting for data...');

                    return self::READ_TIMEOUT;
                }

                return $status;
            case self::READ_FAILED:
                $this->stop();

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
     * Do the actual start.
     *
     * @param int $signal The signal to send the process to kill it
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
     * Log one message.
     *
     * @param string  $level   The level of the log (emergency, alert, critical, error, warning, notice, info, debug)
     * @param string  $message The message to log
     * @param mixed[] $context The context of the message
     */
    abstract protected function logMessage(string $level, string $message, array $context = []): void;

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
     * Return the string to identify this process.
     */
    protected function getDisplay(): string
    {
        return $this->groupConfig->getCommand().'/'.$this->id.'/'.$this->groupId;
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
