<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers\Remote;

use giudicelli\DistributedArchitecture\Master\ConfigInterface;
use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\Handlers\AbstractProcess;
use giudicelli\DistributedArchitecture\Master\Handlers\Local\Config as ConfigLocal;
use giudicelli\DistributedArchitecture\Master\Launcher;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Slave\Handler;
use Psr\Log\LoggerInterface;

/**
 * A process started on a remote host. It works hand in hand with Slave\Handler to send it commands.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
class Process extends AbstractProcess
{
    protected $privateKey = '';

    protected $username = '';

    protected $connection = [];

    protected $stream;

    public function __construct(
        string $host,
        int $id,
        int $groupId,
        int $groupCount,
        GroupConfigInterface $groupConfig,
        ProcessConfigInterface $config,
        LoggerInterface $logger = null
    ) {
        parent::__construct($id, $groupId, $groupCount, $groupConfig, $config, $logger);

        if (!($config instanceof Config)) {
            throw new \InvalidArgumentException('config must be an instance of '.Config::class);
        }

        $this->host = $host;

        $user = posix_getpwuid(posix_getuid());
        if ($config->getUsername()) {
            $this->username = $config->getUsername();
        } else {
            $this->username = $user['name'];
        }

        if ($config->getPrivateKey()) {
            $this->privateKey = $config->getPrivateKey();
        } else {
            $this->privateKey = $user['dir'].'/.ssh/id_rsa';
        }
        if (!@is_readable($this->privateKey)) {
            throw new \RuntimeException('Failed to read SSH protected key file: '.$this->privateKey);
        }
        if (!@is_readable($this->privateKey.'.pub')) {
            throw new \RuntimeException('Failed to read SSH public key file: '.$this->privateKey.'.pub');
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigClass(): string
    {
        return Config::class;
    }

    /**
     * {@inheritdoc}
     */
    public static function instanciate(?LoggerInterface $logger, GroupConfigInterface $groupConfig, ProcessConfigInterface $config, int $idStart, int $groupIdStart, int $groupCount): array
    {
        $class = get_called_class();

        if (!is_a($config, $class::getConfigClass())) {
            return [];
        }
        if (!($config instanceof Config)) {
            return [];
        }

        $children = [];
        $id = $idStart;
        $groupId = $groupIdStart;
        foreach ($config->getHosts() as $host) {
            $children[] = new $class($host, $id, $groupId, $groupCount, $groupConfig, $config, $logger);
            $id += $config->getInstancesCount();
            $groupId += $config->getInstancesCount();
        }

        return $children;
    }

    /**
     * {@inheritdoc}
     */
    public static function willStartCount(ConfigInterface $config): int
    {
        if (!($config instanceof Config)) {
            return 0;
        }

        return count($config->getHosts()) * $config->getInstancesCount();
    }

    /**
     * {@inheritdoc}
     */
    public function softStop(): void
    {
        $this->sendSignal(SIGTERM);
    }

    /**
     * {@inheritdoc}
     */
    protected function run(): bool
    {
        $cmd = $this->buildShellCommand(Handler::PARAM_COMMAND_LAUNCH);

        $info = $this->remoteExec($cmd);
        if (!$info) {
            return false;
        }
        $this->connection = $info['connection'];
        $this->stream = $info['stream'];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function readLine(string &$line): int
    {
        // Handle was closed somewhere else
        if (empty($this->stream)) {
            return self::READ_FAILED;
        }
        if (!@stream_get_meta_data($this->stream) || feof($this->stream)) {
            return self::READ_FAILED;
        }
        $line = trim(@fgets($this->stream));
        if (!$line) {
            return self::READ_EMPTY;
        }

        return self::READ_SUCCESS;
    }

    /**
     * {@inheritdoc}
     */
    protected function kill(int $signal = 0): void
    {
        if ($signal) {
            $this->sendSignal($signal);
        }

        // Cleaning handles
        if (!empty($this->stream)) {
            @fclose($this->stream);
            $this->stream = null;
        }
        if (!empty($this->connection)) {
            @ssh2_disconnect($this->connection);
            $this->connection = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function logMessage(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}('[{group}] [{host}] '.$message, array_merge([
                'group' => $this->groupConfig->getName(),
                'host' => $this->host,
            ], $context));
        } else {
            foreach ($context as $key => $value) {
                $message = str_replace('{'.$key.'}', $value, $message);
            }
            echo "{level:{$level}}{$message}\n";
            flush();
        }
    }

    /**
     * Build the shell command to be executed for this process.
     *
     * @param string $command     the specific command to be interpreted by the Slave\Handler class
     * @param array  $extraParams some extra params to pass to the shell command
     *
     * @return string the shell command
     */
    protected function buildShellCommand(string $command, ?array $extraParams = null): string
    {
        if ($this->config->getPath()) {
            $path = $this->config->getPath();
        } elseif ($this->groupConfig->getPath()) {
            $path = $this->groupConfig->getPath();
        } else {
            $path = getcwd();
        }

        $params = $this->buildParams();
        $params[Handler::PARAM_COMMAND] = $command;
        $params[Handler::PARAM_CONFIG] = $this->config;
        $params[Handler::PARAM_CONFIG_CLASS] = $this->getRemoteConfigClass();
        $params[Handler::PARAM_LAUNCHER_CLASS] = $this->getRemoteLauncherClass();

        if ($extraParams) {
            $params = array_merge($params, $extraParams);
        }

        return '(cd '.$path.' && '.$this->getShellCommand($params).') 2>&1';
    }

    /**
     * Return the class used for the config. It determines the type of process the Slave\Handler class will launch.
     *
     * @return string the class
     */
    protected function getRemoteConfigClass(): string
    {
        return ConfigLocal::class;
    }

    /**
     * Return the class the Slave\Handler class will create to launch the process.
     *
     * @return string the class
     */
    protected function getRemoteLauncherClass(): string
    {
        return Launcher::class;
    }

    /**
     * Send a signal to the remote process.
     *
     * @param int $signal the signal to send
     */
    protected function sendSignal(int $signal): void
    {
        $cmd = $this->buildShellCommand(Handler::PARAM_COMMAND_KILL, [
            Handler::PARAM_SIGNAL => $signal,
        ]);

        $info = $this->remoteExec($cmd);
        if (!$info) {
            return;
        }

        $timeout = $this->getTimeout();
        if (!$timeout) {
            $timeout = 30;
        }
        $st = time();
        while (@stream_get_meta_data($info['stream']) && !feof($info['stream'])) {
            if ((time() - $st) >= $timeout) {
                break;
            }
            $line = trim(@fgets($info['stream']));
            if ($line) {
                $this->logMessage('debug', $line);
            } else {
                usleep(300000);
            }
        }
        @fclose($info['stream']);
        @ssh2_disconnect($info['connection']);
    }

    /**
     * Execute a command on the remote host.
     *
     * @param string $cmd the command to execute
     *
     * @return array the result with key "connection" that holds the connection handle, and key "stream" that hold the stream resource to perform read on
     */
    protected function remoteExec(string $cmd): ?array
    {
        // Connect
        $timeout = ini_set('default_socket_timeout', 5);
        $connection = null;
        for ($i = 0; $i < 5; ++$i) {
            $connection = @ssh2_connect($this->host);
            if (empty($connection)) {
                sleep(1);
            } else {
                break;
            }
        }
        ini_set('default_socket_timeout', $timeout);

        if (empty($connection)) {
            $this->logMessage('error', 'SSH connection failed');

            return null;
        }
        $this->logMessage('debug', 'Connected to host');

        // Pubkey authent
        $result = false;
        for ($i = 0; $i < 5; ++$i) {
            if (!($result = @ssh2_auth_pubkey_file($connection, $this->username, $this->privateKey.'.pub', $this->privateKey))) {
                sleep(1);
            } else {
                break;
            }
        }
        if (!$result) {
            $this->logMessage('error', 'Authentication failed using '.$this->username.' and '.$this->privateKey);
            @ssh2_disconnect($connection);

            return null;
        }

        // Exec
        $stream = null;
        for ($i = 0; $i < 5; ++$i) {
            $stream = @ssh2_exec($connection, "{$cmd}; exit");
            if (empty($stream)) {
                sleep(1);
            } else {
                break;
            }
        }
        if (empty($stream)) {
            $this->logMessage('error', 'Exec failed for '.$cmd);
            @ssh2_disconnect($connection);

            return null;
        }

        stream_set_blocking($stream, 0);
        stream_set_read_buffer($stream, 0);

        return [
            'connection' => $connection,
            'stream' => $stream,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getTimeout(): int
    {
        // We need to overide this method.
        // The local processes that will be launched
        // will have their own timeout and
        // their value will be the same as this
        // remote process.
        // So we might end up killing the remote process before
        // its local process children have a chance to be restarted.

        $timeout = parent::getTimeout();
        if (!$timeout) {
            return 0;
        }

        return $timeout + 30;
    }
}
