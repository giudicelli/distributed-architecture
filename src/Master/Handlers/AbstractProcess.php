<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers;

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
    protected $status = '';
    protected $lastSeen = 0;
    protected $id = 0;
    protected $groupId = 0;
    protected $timeoutsCount = 0;
    protected $logger;
    protected $host = 'localhost';

    /** @var ProcessConfig */
    protected $config;

    /** @var GroupConfig */
    protected $groupConfig;

    public function __construct(
        int $id,
        int $groupId,
        GroupConfig $groupConfig,
        ProcessConfig $config,
        LoggerInterface $logger = null
    ) {
        if (!$groupConfig->getCommand()) {
            throw new \InvalidArgumentException('Missing command for: '.json_encode($groupConfig));
        }

        $this->id = $id;
        $this->groupId = $groupId;
        $this->groupConfig = $groupConfig;
        $this->config = $config;
        $this->logger = $logger;
        $this->display = $groupConfig->getCommand().'/'.$this->id.'/'.$this->groupId;
    }

    public function stop(int $signal = 0): void
    {
        $this->kill($signal);
        if (self::STATUS_STOPPED !== $this->status) {
            $this->status = self::STATUS_STOPPED;
            $this->logMessage('notice', 'Ended');
        }
    }

    public function restart(int $signal = 0): bool
    {
        $this->stop($signal);

        return $this->start();
    }

    public function read(): int
    {
        $line = '';
        $status = $this->readLine($line);
        switch ($status) {
            case self::READ_SUCCESS:
                $this->timeoutsCount = 0;
                $this->lastSeen = time();

                if (Handler::ENDED_MESSAGE === $line) {
                    // The child procces is exiting
                    $this->stop();

                    return self::READ_EMPTY;
                }
                if (Handler::PING_MESSAGE === $line) {
                    return self::READ_EMPTY;
                }

                $matches = [];
                if (preg_match('/^[{]level:([a-z]+)[}]/', $line, $matches)) {
                    $this->logMessage($matches[1], substr($line, strlen($matches[0])));
                } else {
                    $this->logMessage('info', $line);
                }

                return $status;
            case self::READ_EMPTY:
                if ($this->config->getTimeout()) {
                    $timeout = $this->config->getTimeout();
                } elseif ($this->groupConfig->getTimeout()) {
                    $timeout = $this->groupConfig->getTimeout();
                } else {
                    $timeout = 0;
                }
                if ($timeout && (time() - $this->lastSeen) >= $timeout) {
                    ++$this->timeoutsCount;
                    $this->logMessage('error', 'Process has not sent any data in a while, killing it.');
                    $this->stop(SIGKILL);

                    return self::READ_TIMEOUT;
                }

                return $status;
            case self::READ_FAILED:
                $this->stop();

                return $status;
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTimeoutsCount(): int
    {
        return $this->timeoutsCount;
    }

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
            Handler::PARAM_ID => $this->id,
            Handler::PARAM_GROUP_ID => $this->groupId,
            Handler::PARAM_GROUP_CONFIG => $this->groupConfig,
            Handler::PARAM_GROUP_CONFIG_CLASS => get_class($this->groupConfig),
        ];
    }
}
