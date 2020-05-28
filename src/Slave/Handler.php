<?php

namespace giudicelli\DistributedArchitecture\Slave;

use giudicelli\DistributedArchitecture\Helper\ProcessHelper;
use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;

class Handler implements StoppableInterface, HandlerInterface
{
    const PARAM_PREFIX = 'gda_';
    const PARAM_ID = self::PARAM_PREFIX.'id';
    const PARAM_GROUP_ID = self::PARAM_PREFIX.'groupId';
    const PARAM_GROUP_CONFIG = self::PARAM_PREFIX.'groupConfig';
    const PARAM_GROUP_CONFIG_CLASS = self::PARAM_PREFIX.'groupConfigClass';

    const PARAM_COMMAND = self::PARAM_PREFIX.'command';
    const PARAM_SIGNAL = self::PARAM_PREFIX.'signal';
    const PARAM_LAUNCHER_CLASS = self::PARAM_PREFIX.'launcherClass';
    const PARAM_CONFIG = self::PARAM_PREFIX.'config';
    const PARAM_CONFIG_CLASS = self::PARAM_PREFIX.'configClass';

    const PARAM_COMMAND_KILL = 'kill';
    const PARAM_COMMAND_LAUNCH = 'launch';

    const PING_MESSAGE = 'Handler::ping';
    const ENDED_MESSAGE = 'Handler::ended';

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

    public function mustStop(): bool
    {
        return $this->mustStop;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getGroupConfig(): GroupConfigInterface
    {
        return $this->groupConfig;
    }

    public function sendPing(): void
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
     * Let the master process know we're done. It needs to be call just before the process exits.
     */
    public function sendEnded(): void
    {
        echo self::ENDED_MESSAGE."\n";
        flush();
    }

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
        $this->sendPing();

        return true;
    }

    public function stop(): void
    {
        $this->mustStop = true;
    }

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
        if (empty($this->params[self::PARAM_ID])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_ID.' in params');
        }
        $this->id = $this->params[self::PARAM_ID];

        if (empty($this->params[self::PARAM_GROUP_ID])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_GROUP_ID.' in params');
        }
        $this->groupId = $this->params[self::PARAM_GROUP_ID];

        $this->loadGroupConfigObject();
    }

    protected function loadGroupConfigObject(): void
    {
        if (empty($this->params[self::PARAM_GROUP_CONFIG])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_GROUP_CONFIG.' in params');
        }

        if (empty($this->params[self::PARAM_GROUP_CONFIG_CLASS])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_GROUP_CONFIG_CLASS.' in params');
        }

        $class = $this->params[self::PARAM_GROUP_CONFIG_CLASS];
        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->implementsInterface(GroupConfigInterface::class)
            || !$reflectionClass->isInstantiable()) {
            throw new \InvalidArgumentException('Class "'.$class.'" must implement "'.GroupConfigInterface::class.'" and be instanciable');
        }

        $this->groupConfig = new $class();
        $this->groupConfig->fromArray($this->params[self::PARAM_GROUP_CONFIG]);
    }

    protected function isCommand(): bool
    {
        return !empty($this->params[self::PARAM_COMMAND]);
    }

    protected function handleCommand(): void
    {
        if (!$this->isCommand()) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_COMMAND.' in params');
        }

        $processConfig = $this->getCommandConfigObject();
        switch ($this->params[self::PARAM_COMMAND]) {
            case self::PARAM_COMMAND_KILL:
                $this->handleKill($processConfig);

            break;
            case self::PARAM_COMMAND_LAUNCH:
                $this->handleLaunch($processConfig);

            break;
            default:
                throw new \InvalidArgumentException('Unknown value "'.$this->params[self::PARAM_COMMAND].'" from '.self::PARAM_COMMAND.' in params');
        }
    }

    protected function getCommandConfigObject(): ProcessConfigInterface
    {
        if (empty($this->params[self::PARAM_CONFIG])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_CONFIG.' in params');
        }

        if (empty($this->params[self::PARAM_CONFIG_CLASS])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_CONFIG_CLASS.' in params');
        }

        $class = $this->params[self::PARAM_CONFIG_CLASS];

        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->implementsInterface(ProcessConfigInterface::class)
            || !$reflectionClass->isInstantiable()) {
            throw new \InvalidArgumentException('Class "'.$class.'" must implement "'.ProcessConfigInterface::class.'" and be instanciable');
        }

        $config = new $class();
        $config->fromArray($this->params[self::PARAM_CONFIG]);

        return $config;
    }

    protected function getCommandLauncherObject(): LauncherInterface
    {
        if (empty($this->params[self::PARAM_LAUNCHER_CLASS])) {
            throw new \InvalidArgumentException('Expected '.self::PARAM_LAUNCHER_CLASS.' in params');
        }

        $class = $this->params[self::PARAM_LAUNCHER_CLASS];

        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->implementsInterface(LauncherInterface::class)
        || !$reflectionClass->isInstantiable()) {
            throw new \InvalidArgumentException('Class "'.$class.'" must implement "'.LauncherInterface::class.'" and be instanciable');
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

        if (empty($this->params[self::PARAM_SIGNAL])) {
            posix_kill($pid, SIGTERM);
        } elseif (SIGKILL === $this->params[self::PARAM_SIGNAL]) {
            ProcessHelper::kill($pid, SIGKILL);
        } else {
            posix_kill($pid, $this->params[self::PARAM_SIGNAL]);
        }
    }
}
