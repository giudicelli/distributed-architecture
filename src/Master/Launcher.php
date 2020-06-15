<?php

namespace giudicelli\DistributedArchitecture\Master;

use giudicelli\DistributedArchitecture\AbstractStoppable;
use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Config\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Helper\InterProcessLogger;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Process as LocalProcess;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Process as RemoteProcess;
use Psr\Log\LoggerInterface;

/**
 * This class is the implementation of the LauncherInterface interface. Its main role is to launch processes.
 *
 *  @author Frédéric Giudicelli
 */
class Launcher extends AbstractStoppable implements LauncherInterface
{
    protected $timeout = 300;

    protected $maxRunningTime = 0;

    protected $maxProcessTimeout = 3;

    protected $startedTime = 0;

    protected $logger;

    /** @var array<ProcessInterface> */
    protected $children = [];

    protected $mappingConfigProcess;

    /** @var bool */
    protected $isMaster;

    /** @var EventsInterface */
    protected $events;

    /** @var array<SuspendableGroupConfig> */
    protected $groupConfigs;

    /**
     * @param bool $isMaster true when this instance is the main master, false when it's a remote launcher
     */
    public function __construct(
        bool $isMaster,
        ?LoggerInterface $logger = null
    ) {
        $this->isMaster = $isMaster;
        $this->logger = new InterProcessLogger($isMaster, $logger);

        $this->loadReflectionData();

        set_time_limit(0);

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [&$this, 'signalHandler']);
    }

    /**
     * {@inheritdoc}
     */
    public function setTimeout(?int $timeout): LauncherInterface
    {
        $this->timeout = $timeout > 5 ? $timeout : 5;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
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
    public function setEventsHandler(?EventsInterface $events): LauncherInterface
    {
        $this->events = $events;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventsHandler(): ?EventsInterface
    {
        return $this->events;
    }

    /**
     * {@inheritdoc}
     */
    public function setGroupConfigs(array $groupConfigs): LauncherInterface
    {
        // Validate the config
        $this->checkGroupConfigs($groupConfigs);

        if ($this->isMaster()) {
            // We're the master, we need to transform the groups into SuspendableGroupConfig
            $this->groupConfigs = [];
            foreach ($groupConfigs as $groupConfig) {
                $this->groupConfigs[] = new SuspendableGroupConfig($groupConfig);
            }
        } else {
            $this->groupConfigs = $groupConfigs;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): void
    {
        if (!$this->isMaster()) {
            parent::ping();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function runMaster(bool $neverExit = false): void
    {
        $this->mustStop = false;
        do {
            // Wait while all groups are suspended
            do {
                $allSuspended = true;
                foreach ($this->groupConfigs as $groupConfig) {
                    if (!$groupConfig->isSuspended()) {
                        $allSuspended = false;

                        break;
                    }
                }
                if ($allSuspended) {
                    if ($this->events) {
                        $this->events->check($this);
                    }
                    if (!$this->sleep(1, false)) {
                        return;
                    }
                }
            } while ($allSuspended);

            if ($this->events) {
                $this->events->starting($this);
            }

            // Start all groups
            $idStart = 1;
            foreach ($this->groupConfigs as $groupConfig) {
                if ($this->mustStop()) {
                    break;
                }
                $idStart += $this->startGroup($groupConfig, $idStart, 1, $this->countGroup($groupConfig));
            }
            if ($this->events) {
                $this->events->started($this);
            }

            $this->handleChildren();

            $this->children = [];
        } while ($neverExit && !$this->mustStop());

        if ($this->events) {
            $this->events->stopped($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function runRemote(int $idStart, int $groupIdStart, int $groupCount): void
    {
        if ($this->events) {
            $this->events->starting($this);
        }

        if (empty($this->groupConfigs) || 1 !== count($this->groupConfigs)) {
            throw new \InvalidArgumentException('Expected 1 single group');
        }

        if (empty($this->groupConfigs[0]->getProcessConfigs()) || 1 !== count($this->groupConfigs[0]->getProcessConfigs())) {
            throw new \InvalidArgumentException('Expected 1 single process in the group');
        }

        $this->startGroupProcess(
            $this->groupConfigs[0],
            $this->groupConfigs[0]->getProcessConfigs()[0],
            $idStart,
            $groupIdStart,
            $groupCount
        );

        if ($this->events) {
            $this->events->started($this);
        }

        $this->handleChildren();

        if ($this->events) {
            $this->events->stopped($this);
        }

        $this->children = [];
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isMaster(): bool
    {
        return $this->isMaster;
    }

    /**
     * {@inheritdoc}
     */
    public function resumeGroup(string $groupName): void
    {
        if (!$this->isMaster()) {
            return;
        }

        // Resume the group
        foreach ($this->groupConfigs as $groupConfig) {
            if ($groupConfig->getName() === $groupName) {
                $groupConfig->setSuspended(false);

                break;
            }
        }

        if ($this->isRunning()) {
            // We're running, we need to start the processes
            // If we're not running, the processes will be
            // started in runMaster
            foreach ($this->children as $child) {
                if ($child->getGroupConfig()->getName() !== $groupName) {
                    continue;
                }
                $child->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function resumeAll(): void
    {
        if (!$this->isMaster()) {
            return;
        }

        // Resume all groups
        foreach ($this->groupConfigs as $groupConfig) {
            $groupConfig->setSuspended(false);
        }

        if ($this->isRunning()) {
            // We're running, we need to start the processes
            // If we're not running, the processes will be
            // started in runMaster
            foreach ($this->children as $child) {
                $child->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspendAll(bool $force = false): void
    {
        if (!$this->isMaster()) {
            return;
        }

        // Suspend all groups
        foreach ($this->groupConfigs as $groupConfig) {
            $groupConfig->setSuspended(true);
        }

        foreach ($this->children as $child) {
            if ($force) {
                $child->stop(SIGKILL);
            } else {
                $child->softStop();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspendGroup(string $groupName, bool $force = false): void
    {
        if (!$this->isMaster()) {
            return;
        }

        // Suspend the group
        foreach ($this->groupConfigs as $groupConfig) {
            if ($groupConfig->getName() === $groupName) {
                $groupConfig->setSuspended(true);

                break;
            }
        }

        foreach ($this->children as $child) {
            if ($child->getGroupConfig()->getName() !== $groupName) {
                continue;
            }
            if ($force) {
                $child->stop(SIGKILL);
            } else {
                $child->softStop();
            }
        }
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
     * Called before run, to check the group configs.
     *
     * @param array<GroupConfigInterface> $groupConfigs The configuration for each group of processes to check
     */
    protected function checkGroupConfigs(array $groupConfigs): void
    {
        foreach ($groupConfigs as $groupConfig) {
            foreach ($groupConfig->getProcessConfigs() as $processConfig) {
                $this->getConfigProcess($processConfig);
            }
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
            LocalProcess::class,
            RemoteProcess::class,
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
        $children = call_user_func([$processClass, 'instanciate'], $this, $groupConfig, $processConfig, $idStart, $groupIdStart, $groupCount);
        foreach ($children as $child) {
            if (!($groupConfig instanceof SuspendableGroupConfig) ||
                !$groupConfig->isSuspended()) {
                $child->start();
            }
            $this->children[$child->getId()] = $child;
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
        $mustStop = false;

        $this->startedTime = time();

        while ($this->isRunning()) {
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
                $this->ping();
            }

            if ($this->events) {
                $this->events->check($this);
            }

            if (!$stopStartTime) {
                // Stop was not initiated

                if ($mustStop || $this->mustStop()) {
                    // We need to initiate the stop

                    $this->logMessage('notice', 'Stopping...');
                    $stopStartTime = time();

                    // Request a soft stop
                    foreach ($this->children as $child) {
                        if ($child->isRunning()) {
                            $child->softStop();
                        }
                    }
                } elseif ($this->maxRunningTime && (time() - $this->startedTime) > $this->maxRunningTime) {
                    // Did we reach the maximum running time?
                    $mustStop = true;
                } elseif ($this->getTimeout() && (time() - $lastContent) > $this->getTimeout()) {
                    // Did we timeout  waiting for content from our children ?
                    $this->logMessage('error', 'Timeout waiting for content, force kill');

                    // Exit the loop to force kill all remaining children
                    break;
                }
            } elseif ($this->getTimeout() && (time() - $stopStartTime) >= $this->getTimeout()) {
                // Did we timeout waiting for our children to perform a clean exit?
                $this->logMessage('error', 'Timeout waiting for clean shutdown, force kill');

                // Exit the loop to force kill all remaining children
                break;
            }
        }

        // If there there are some remaining children.
        // We need to force kill them
        foreach ($this->children as $child) {
            if (ProcessInterface::STATUS_STOPPED !== $child->getStatus()) {
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
            // We only care about running children
            if (!$child->isRunning()) {
                continue;
            }

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
                        // Task restarted
                        $gotContent = true;
                    } else {
                        // Restart failed, remove it
                        $child->stop();
                    }

                    break;
                case ProcessInterface::READ_FAILED:
                    // Fail during read, we need to stop it
                    $child->stop();

                break;
            }
        }

        return $gotContent;
    }

    protected function logMessage(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, array_merge($context, ['display' => 'master']));
    }
}
