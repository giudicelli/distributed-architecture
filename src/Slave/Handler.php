<?php

namespace giudicelli\DistributedArchitecture\Slave;

use giudicelli\DistributedArchitecture\Helper\ProcessHelper;
use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Config;
use giudicelli\DistributedArchitecture\Master\Handlers\Remote\Process as ProcessRemote;
use giudicelli\DistributedArchitecture\Master\Launcher;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Master\ProcessInterface;

class Handler
{
    protected $mustStop = false;

    protected $id = 0;

    protected $groupId = 0;

    protected $lastSentPing = 0;

    /** @var array */
    protected $params;

    /** @var GroupConfigInterface */
    protected $groupConfig;

    public function __construct(string $params)
    {
        $this->parseParams($params);
    }

    /**
     * Returns whether the process was asked to be stopped.
     *
     * @return bool The stop status
     */
    public function mustStop(): bool
    {
        return $this->mustStop;
    }

    /**
     * @return int The unique id of this process across all groups
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return int The unique id of this process for the group it belongs to
     */
    public function getGroupId(): int
    {
        return $this->groupId;
    }

    /**
     * @return array The group config
     */
    public function getGroupConfig(): GroupConfigInterface
    {
        return $this->groupConfig;
    }

    /**
     * Pings the master process to let it know this current process is still alive.
     * It should be used when handling a rather long task, to avoid having the
     * master process think this process is dead.
     */
    public function sendPing(): void
    {
        // Avoid flooding
        $t = time();
        if (($t - $this->lastSentPing) < 5) {
            return;
        }
        $this->lastSentPing = $t;

        echo ProcessInterface::PING_MESSAGE."\n";
        flush();
    }

    /**
     * Let the master process know we're done. It needs to be call just before the process exits.
     */
    public function sendEnded(): void
    {
        echo ProcessInterface::ENDED_MESSAGE."\n";
        flush();
    }

    /**
     * Sleep a certain time duration. Fail if process is requested to stop and send pings to the master during the wait time.
     *
     * @param int The wait time
     *
     * @return bool Was the wait interrupted by a stop signal
     */
    public function sleep(int $s): bool
    {
        if ($this->mustStop) {
            return false;
        }
        $t = time();
        while ((time() - $t) < $s) {
            if ($this->mustStop) {
                return false;
            }
            usleep(30000);
            $this->sendPing();
        }

        return true;
    }

    /**
     * Run this handler.
     *
     * @param callable $processCallback if we're not dealing with an internal command, this function will be called to handle the actual task
     */
    public function run(callable $processCallback): void
    {
        if ($this->isCommand()) {
            $this->handleCommand();
        } else {
            // We want to know when we're asked to stop
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, [&$this, 'signalHandler']);

            call_user_func($processCallback, $this);
            $this->sendEnded();
        }
    }

    public function signalHandler(int $signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->mustStop = true;

                break;
        }
    }

    protected function parseParams(string $params): void
    {
        $this->params = json_decode($params, true);
        if (empty($this->params)) {
            throw new \InvalidArgumentException('params is not properly encoded');
        }
        if (empty($this->params[ProcessInterface::PARAM_ID])) {
            throw new \InvalidArgumentException('Expected '.ProcessInterface::PARAM_ID.' in params');
        }
        $this->id = $this->params[ProcessInterface::PARAM_ID];

        if (empty($this->params[ProcessInterface::PARAM_GROUP_ID])) {
            throw new \InvalidArgumentException('Expected '.ProcessInterface::PARAM_GROUP_ID.' in params');
        }
        $this->groupId = $this->params[ProcessInterface::PARAM_GROUP_ID];

        $this->loadGroupConfigObject();
    }

    protected function loadGroupConfigObject(): void
    {
        if (empty($this->params[ProcessInterface::PARAM_GROUP_CONFIG])) {
            throw new \InvalidArgumentException('Expected '.ProcessInterface::PARAM_GROUP_CONFIG.' in params');
        }

        if (empty($this->params[ProcessInterface::PARAM_GROUP_CONFIG_CLASS])) {
            throw new \InvalidArgumentException('Expected '.ProcessInterface::PARAM_GROUP_CONFIG_CLASS.' in params');
        }

        $class = $this->params[ProcessInterface::PARAM_GROUP_CONFIG_CLASS];
        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->implementsInterface(GroupConfigInterface::class)
            || !$reflectionClass->isInstantiable()) {
            throw new \InvalidArgumentException('Class "'.$class.'" must implement "'.GroupConfigInterface::class.'" and be instanciable');
        }

        $this->groupConfig = new $class();
        $this->groupConfig->fromArray($this->params[ProcessInterface::PARAM_GROUP_CONFIG]);
    }

    protected function isCommand(): bool
    {
        return !empty($this->params[ProcessRemote::PARAM_COMMAND]);
    }

    protected function handleCommand(): void
    {
        if (!$this->isCommand()) {
            throw new \InvalidArgumentException('Expected '.ProcessRemote::PARAM_COMMAND.' in params');
        }

        $processConfig = $this->getCommandConfigObject();
        switch ($this->params[ProcessRemote::PARAM_COMMAND]) {
            case ProcessRemote::PARAM_COMMAND_KILL:
                $this->handleKill($processConfig);

            break;
            case ProcessRemote::PARAM_COMMAND_LAUNCH:
                $this->handleLaunch($processConfig);

            break;
            default:
                throw new \InvalidArgumentException('Unknown value "'.$this->params[ProcessRemote::PARAM_COMMAND].'" from '.ProcessRemote::PARAM_COMMAND.' in params');
        }
    }

    protected function getCommandConfigObject(): ProcessConfigInterface
    {
        if (empty($this->params[ProcessRemote::PARAM_CONFIG])) {
            throw new \InvalidArgumentException('Expected '.ProcessRemote::PARAM_CONFIG.' in params');
        }

        if (empty($this->params[ProcessRemote::PARAM_CONFIG_CLASS])) {
            throw new \InvalidArgumentException('Expected '.ProcessRemote::PARAM_CONFIG_CLASS.' in params');
        }

        $class = $this->params[ProcessRemote::PARAM_CONFIG_CLASS];

        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->implementsInterface(ProcessConfigInterface::class)
            || !$reflectionClass->isInstantiable()) {
            throw new \InvalidArgumentException('Class "'.$class.'" must implement "'.ProcessConfigInterface::class.'" and be instanciable');
        }

        $config = new $class();
        $config->fromArray($this->params[ProcessRemote::PARAM_CONFIG]);

        return $config;
    }

    protected function getCommandLauncherObject(): Launcher
    {
        if (empty($this->params[ProcessRemote::PARAM_LAUNCHER_CLASS])) {
            throw new \InvalidArgumentException('Expected '.ProcessRemote::PARAM_LAUNCHER_CLASS.' in params');
        }

        $class = $this->params[ProcessRemote::PARAM_LAUNCHER_CLASS];

        if (Launcher::class !== $class) {
            $reflectionClass = new \ReflectionClass($class);
            if (!$reflectionClass->isSubclassOf(Launcher::class)
            || !$reflectionClass->isInstantiable()) {
                throw new \InvalidArgumentException('Class "'.$class.'" must an instance of "'.Launcher::class.'" and be instanciable');
            }
        }

        return new $class();
    }

    protected function getPidFileFromConfig(ProcessConfigInterface $config): string
    {
        $uniqueId = sha1($this->id.'-'.$this->groupId.'-'.$this->groupConfig->getHash().'-'.$config->getHash());

        return sys_get_temp_dir().'/gda-'.$uniqueId.'.pid';
    }

    protected function handleLaunch(ProcessConfigInterface $config): void
    {
        // Save the pid file, it will allow handleKill to find us
        $pidFile = $this->getPidFileFromConfig($config);
        file_put_contents($pidFile, getmypid());

        $masterProcess = $this->getCommandLauncherObject();
        $masterProcess->runSingle($this->groupConfig, $config, $this->id, $this->groupId);

        @unlink($pidFile);
    }

    protected function handleKill(ProcessConfigInterface $config): void
    {
        $pidFile = $this->getPidFileFromConfig($config);
        $pid = @file_get_contents($pidFile);
        if (empty($pid)) {
            return;
        }

        if (empty($this->params[ProcessRemote::PARAM_SIGNAL])) {
            posix_kill($pid, SIGTERM);
        } elseif (SIGKILL === $this->params[ProcessRemote::PARAM_SIGNAL]) {
            ProcessHelper::kill($pid, SIGKILL);
        } else {
            posix_kill($pid, $this->params[ProcessRemote::PARAM_SIGNAL]);
        }
    }
}
