<?php

namespace giudicelli\DistributedArchitecture\Master;

use giudicelli\DistributedArchitecture\Master\Handlers\Local\Process as ProcessLocal;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Process as ProcessRemote;
use Psr\Log\LoggerInterface;

/**
 * This class is the implementation of the LauncherInterface interface. Its main role is to launch processes.
 *
 *  @author Frédéric Giudicelli
 */
class Launcher implements LauncherInterface
{
    protected $mustStop = false;

    protected $timeout = 300;

    protected $maxRunningTime = 0;

    protected $maxProcessTimeout = 3;

    protected $startedTime = 0;

    protected $logger;

    /** @var array<ProcessInterface> */
    protected $children = [];

    protected $mappingConfigProcess;

    public function __construct(
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger;

        $this->loadReflectionData();

        set_time_limit(0);

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [&$this, 'signalHandler']);
    }

    public function stop(): void
    {
        $this->mustStop = true;
    }

    public function setTimeout(?int $timeout): LauncherInterface
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function setMaxRunningTime(?int $maxRunningTime): LauncherInterface
    {
        $this->maxRunningTime = $maxRunningTime;

        return $this;
    }

    public function setMaxProcessTimeout(?int $maxProcessTimeout): LauncherInterface
    {
        $this->maxProcessTimeout = $maxProcessTimeout;

        return $this;
    }

    public function run(array $groupConfigs): void
    {
        // Start everything
        $idStart = 1;
        foreach ($groupConfigs as $groupConfig) {
            if ($this->mustStop) {
                break;
            }
            $idStart += $this->startGroup($groupConfig, $idStart);
        }

        $this->startedTime = time();
        $this->handleChildren();
        $this->reset();
    }

    public function runSingle(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart): void
    {
        // Start
        $this->startGroupProcess($groupConfig, $processConfig, $idStart, $groupIdStart);

        $this->startedTime = time();

        $this->handleChildren();
        $this->reset();
    }

    /**
     * @internal
     */
    public function signalHandler(int $signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->logMessage('notice', 'Received SIGTERM, stopping');
                $this->stop();

                break;
        }
    }

    protected function getProcessHandlersList(): array
    {
        return [
            ProcessLocal::class,
            ProcessRemote::class,
        ];
    }

    protected function loadReflectionData()
    {
        foreach ($this->getProcessHandlersList() as $processClass) {
            if (!in_array(ProcessInterface::class, class_implements($processClass))) {
                throw new \InvalidArgumentException('Class "'.$processClass.'" must implement "'.ProcessInterface::class.'"');
            }
            $configClass = call_user_func([$processClass, 'getConfigClass']);
            $this->mappingConfigProcess[$configClass] = $processClass;
        }
    }

    protected function startGroup(GroupConfigInterface $groupConfig, int $idStart, int $groupIdStart = 1): int
    {
        $processesCount = 0;
        foreach ($groupConfig->getProcessConfigs() as $processConfig) {
            $count = $this->startGroupProcess($groupConfig, $processConfig, $idStart, $groupIdStart);

            $idStart += $count;
            $groupIdStart += $count;
            $processesCount += $count;
        }

        return $processesCount;
    }

    protected function startGroupProcess(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart): int
    {
        $processConfigClass = get_class($processConfig);
        if (!isset($this->mappingConfigProcess[$processConfigClass])) {
            throw new \InvalidArgumentException('Config class "'.$processConfigClass.'" is not handled by any "'.ProcessInterface::class.'"');
        }

        $processClass = $this->mappingConfigProcess[$processConfigClass];
        $children = call_user_func([$processClass, 'instanciate'], $this->logger, $groupConfig, $processConfig, $idStart, $groupIdStart);
        foreach ($children as $child) {
            if ($child->start()) {
                $this->children[$child->getId()] = $child;
            }
        }

        return call_user_func([$processClass, 'willStartCount'], $processConfig);
    }

    protected function handleChildren(): void
    {
        $lastContent = time();
        $stopping = false;
        $stopStartTime = 0;

        while (count($this->children)) {
            if ($this->readChildren(0 !== $stopStartTime)) {
                $lastContent = time();
                if ($stopping) {
                    // Go fast to process the last data
                    usleep(50);
                } else {
                    // 50ms
                    usleep(50000);
                }
            } else {
                // 100ms
                usleep(100000);
            }

            if (!$stopStartTime) {
                // Stop was not initiated

                if ($this->mustStop) {
                    // We need to initiate the stop

                    $this->logMessage('notice', 'Stopping...');
                    $stopStartTime = time();

                    // Request a soft stop
                    foreach ($this->children as $child) {
                        $child->softStop();
                    }
                } elseif ($this->maxRunningTime && (time() - $this->startedTime) > $this->maxRunningTime) {
                    // Did we reach the maximum running time?
                    $this->stop();
                } elseif ($this->timeout && (time() - $lastContent) > $this->timeout) {
                    // Did we timeout  waiting for content from our children ?
                    $this->logMessage('error', 'Timeout waiting for content, force kill');

                    // Exit the loop to force kill all remaining children
                    break;
                }
            } elseif ($this->timeout && (time() - $stopStartTime) >= $this->timeout) {
                // Did we timeout waiting for our children to perform a clean exit?
                $this->logMessage('error', 'Timeout waiting for clean shutdown, force kill');

                // Exit the loop to force kill all remaining children
                break;
            }
        }

        if (!empty($this->children)) {
            // There are some remaining s.
            // We need to force kill them
            sleep(30);
            foreach ($this->children as $child) {
                $child->stop(SIGKILL);
            }
        }
    }

    protected function readChildren(bool $stopping): bool
    {
        $gotContent = false;
        $processesToRemove = [];
        foreach ($this->children as $id => $child) {
            // Read content from process
            switch ($child->read()) {
                case ProcessInterface::READ_SUCCESS:
                    $gotContent = true;

                    break;
                case ProcessInterface::READ_TIMEOUT:
                    if ($stopping || ($this->maxProcessTimeout && $child->getTimeoutsCount() >= $this->maxProcessTimeout)) {
                        // We need to remove this process
                        $processesToRemove[] = $id;
                    } else {
                        // Try to restart task
                        if (!$child->restart()) {
                            $processesToRemove[] = $id;
                        } else {
                            $gotContent = true;
                        }
                    }

                    break;
                case ProcessInterface::READ_FAILED:
                    $processesToRemove[] = $id;

                    break;
            }
        }
        foreach ($processesToRemove as $id) {
            $this->removeChild($id);
        }

        return $gotContent;
    }

    protected function removeChild($id): void
    {
        if (!isset($this->children[$id])) {
            return;
        }
        $this->children[$id]->stop();
        unset($this->children[$id]);
    }

    protected function reset(): void
    {
        $this->mustStop = false;
        $this->children = [];
    }

    protected function logMessage(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}('[master] '.$message, $context);
        } else {
            foreach ($context as $key => $value) {
                $message = str_replace('{'.$key.'}', $value, $message);
            }
            echo "{level:{$level}}[master] {$message}\n";
            flush();
        }
    }
}
