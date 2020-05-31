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
    public function setTimeout(?int $timeout): LauncherInterface
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxRunningTime(?int $maxRunningTime): LauncherInterface
    {
        $this->maxRunningTime = $maxRunningTime;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxProcessTimeout(?int $maxProcessTimeout): LauncherInterface
    {
        $this->maxProcessTimeout = $maxProcessTimeout;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function run(array $groupConfigs): void
    {
        // First count the total number of processes
        // that will be launched in each group
        $groupCounts = [];
        foreach ($groupConfigs as $index => $groupConfig) {
            $groupCounts[$index] = $this->countGroup($groupConfig);
        }

        // Start everything
        $idStart = 1;
        foreach ($groupConfigs as $index => $groupConfig) {
            if ($this->mustStop) {
                break;
            }
            $idStart += $this->startGroup($groupConfig, $idStart, 1, $groupCounts[$index]);
        }

        $this->startedTime = time();
        $this->handleChildren();
        $this->reset();
    }

    /**
     * {@inheritdoc}
     */
    public function runSingle(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart, int $groupCount): void
    {
        // Start
        $this->startGroupProcess($groupConfig, $processConfig, $idStart, $groupIdStart, $groupCount);

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

    /**
     * Return the list of ProcessInterface handled by this launcher.
     *
     * @return array<string> The list of ProcessInterface classes
     */
    protected function getProcessHandlersList(): array
    {
        return [
            ProcessLocal::class,
            ProcessRemote::class,
        ];
    }

    /**
     * Build the list of mapped ProcessConfigInterface/ProcessInterface. Each ProcessInterface can handle a single ProcessConfigInterface and each ProcessConfigInterface is handled by a single ProcessInterface. The method will let us know which ProcessInterface to create depending on the ProcessConfigInterface passed by the user.
     */
    protected function loadReflectionData(): void
    {
        foreach ($this->getProcessHandlersList() as $processClass) {
            if (!in_array(ProcessInterface::class, class_implements($processClass))) {
                throw new \InvalidArgumentException('Class "'.$processClass.'" must implement "'.ProcessInterface::class.'"');
            }
            $configClass = call_user_func([$processClass, 'getConfigClass']);
            $this->mappingConfigProcess[$configClass] = $processClass;
        }
    }

    /**
     * Count the number of processes that will be launched for a certain group.
     *
     * @param GroupConfigInterface $groupConfig The group configuration
     *
     * @return int the number of processes
     */
    protected function countGroup(GroupConfigInterface $groupConfig): int
    {
        $count = 0;
        foreach ($groupConfig->getProcessConfigs() as $processConfig) {
            $processClass = $this->getConfigProcess($processConfig);
            $count += call_user_func([$processClass, 'willStartCount'], $processConfig);
        }

        return $count;
    }

    /**
     * Start a group of processes.
     *
     * @param GroupConfigInterface $groupConfig  The group configuration
     * @param int                  $idStart      The current value of the global id
     * @param int                  $groupIdStart The current value of the group id
     * @param int                  $groupCount   The total number of processes in the group
     *
     * @return int the number of started processes
     */
    protected function startGroup(GroupConfigInterface $groupConfig, int $idStart, int $groupIdStart, int $groupCount): int
    {
        $processesCount = 0;
        foreach ($groupConfig->getProcessConfigs() as $processConfig) {
            $count = $this->startGroupProcess($groupConfig, $processConfig, $idStart, $groupIdStart, $groupCount);

            $idStart += $count;
            $groupIdStart += $count;
            $processesCount += $count;
        }

        return $processesCount;
    }

    /**
     * Start processes defined in a ProcessConfigInterface.
     *
     * @param GroupConfigInterface   $groupConfig   The group configuration
     * @param ProcessConfigInterface $processConfig The process configuration
     * @param int                    $idStart       The current value of the global id
     * @param int                    $groupIdStart  The current value of the group id
     * @param int                    $groupCount    The total number of processes in the group
     *
     * @return int the number of started processes
     */
    protected function startGroupProcess(GroupConfigInterface $groupConfig, ProcessConfigInterface $processConfig, int $idStart, int $groupIdStart, int $groupCount): int
    {
        $processClass = $this->getConfigProcess($processConfig);
        $children = call_user_func([$processClass, 'instanciate'], $this->logger, $groupConfig, $processConfig, $idStart, $groupIdStart, $groupCount);
        foreach ($children as $child) {
            if ($child->start()) {
                $this->children[$child->getId()] = $child;
            }
        }

        return call_user_func([$processClass, 'willStartCount'], $processConfig);
    }

    /**
     * Return the ProcessInterface class that handles the ProcessConfigInterface.
     *
     * @param ProcessConfigInterface $processConfig The config
     *
     * @return string The ProcessInterface class
     */
    protected function getConfigProcess(ProcessConfigInterface $processConfig): string
    {
        $processConfigClass = get_class($processConfig);
        if (!isset($this->mappingConfigProcess[$processConfigClass])) {
            throw new \InvalidArgumentException('Config class "'.$processConfigClass.'" is not handled by any "'.ProcessInterface::class.'"');
        }

        return $this->mappingConfigProcess[$processConfigClass];
    }

    /**
     * Handle all the children processes.
     */
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

            // Purge the children that are not running
            foreach ($this->children as $id => $child) {
                if (ProcessInterface::STATUS_RUNNING !== $child->getStatus()) {
                    $this->removeChild($id);
                }
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
            // There are some remaining children.
            // We need to force kill them
            sleep(30);
            foreach ($this->children as $child) {
                $child->stop(SIGKILL);
            }
        }
    }

    /**
     * Perform a read on all the children.
     */
    protected function readChildren(bool $stopping): bool
    {
        $gotContent = false;
        foreach ($this->children as $child) {
            // Read content from process
            switch ($child->read()) {
                case ProcessInterface::READ_SUCCESS:
                    $gotContent = true;

                    break;
                case ProcessInterface::READ_TIMEOUT:
                    if (($this->maxProcessTimeout && $child->getTimeoutsCount() >= $this->maxProcessTimeout)) {
                        $child->stop(SIGKILL);
                    } elseif ($stopping) {
                        // Timeout reading data, and we're stopping
                        // we can stop the child
                        $child->stop();
                    } elseif ($child->restart(SIGKILL)) {
                        // Try to restart task
                        $gotContent = true;
                    }

                    break;
            }
        }

        return $gotContent;
    }

    /**
     * Remove a single child from our children list.
     *
     * @param int $id The id of the child to remove
     */
    protected function removeChild(int $id): void
    {
        if (!isset($this->children[$id])) {
            return;
        }
        $this->children[$id]->stop();
        unset($this->children[$id]);
    }

    /**
     * Reset this launcher.
     */
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
