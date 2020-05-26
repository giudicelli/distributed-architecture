<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers\Local;

use giudicelli\DistributedArchitecture\Helper\ProcessHelper;
use giudicelli\DistributedArchitecture\Master\ConfigInterface;
use giudicelli\DistributedArchitecture\Master\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Master\Handlers\AbstractProcess;
use giudicelli\DistributedArchitecture\Master\ProcessConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * A process started on the same computer as the master.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
class Process extends AbstractProcess
{
    protected $pipes = [];
    protected $proc;
    protected $pid = 0;

    public static function getConfigClass(): string
    {
        return Config::class;
    }

    public static function instanciate(?LoggerInterface $logger, GroupConfigInterface $groupConfig, ProcessConfigInterface $config, int $idStart, int $groupIdStart): array
    {
        $class = get_called_class();

        if (!is_a($config, $class::getConfigClass())) {
            return [];
        }
        if (!($config instanceof Config)) {
            return [];
        }

        $class = get_called_class();

        $children = [];
        for ($i = 0, $id = $idStart, $groupId = $groupIdStart; $i < $config->getInstancesCount(); ++$i, ++$id, ++$groupId) {
            $children[] = new $class($id, $groupId, $groupConfig, $config, $logger);
        }

        return $children;
    }

    public static function willStartCount(ConfigInterface $config): int
    {
        if (!($config instanceof Config)) {
            return 0;
        }

        return $config->getInstancesCount();
    }

    public function start(): bool
    {
        $cmd = $this->buildShellCommand();

        if ($this->config->getPath()) {
            $path = $this->config->getPath();
        } elseif ($this->groupConfig->getPath()) {
            $path = $this->groupConfig->getPath();
        } else {
            $path = getcwd();
        }

        $descspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->pipes = [];
        $this->proc = proc_open($cmd, $descspec, $this->pipes, $path, ['LANG' => 'en_US.UTF-8']);
        if (!is_resource($this->proc)) {
            $this->kill();
            $this->status = self::STATUS_ERROR;

            return false;
        }

        $status = proc_get_status($this->proc);
        if (empty($status['running']) || empty($status['pid'])) {
            $this->kill();
            $this->status = self::STATUS_ERROR;

            return false;
        }

        $this->status = self::STATUS_RUNNING;
        $this->pid = $status['pid'];

        if ($this->config->getPriority()) {
            pcntl_setpriority($this->config->getPriority(), $this->pid);
        } elseif ($this->groupConfig->getPriority()) {
            pcntl_setpriority($this->groupConfig->getPriority(), $this->pid);
        }

        stream_set_blocking($this->pipes[1], 0);
        stream_set_read_buffer($this->pipes[1], 0);

        stream_set_blocking($this->pipes[2], 0);
        stream_set_read_buffer($this->pipes[2], 0);

        $this->lastSeen = time();

        return true;
    }

    public function softStop(): void
    {
        posix_kill($this->pid, SIGTERM);
    }

    protected function readLine(string &$line): int
    {
        // Handle was closed somewhere else
        if (empty($this->pipes[1])) {
            return self::READ_FAILED;
        }
        if (!@stream_get_meta_data($this->pipes[1]) || feof($this->pipes[1])) {
            return self::READ_FAILED;
        }

        $line = trim(@fgets($this->pipes[1]));
        if (!$line) {
            $line = trim(@fgets($this->pipes[2]));
            if (!$line) {
                $status = proc_get_status($this->proc);
                if (empty($status['running'])) {
                    return self::READ_FAILED;
                }

                return self::READ_EMPTY;
            }
        }

        return self::READ_SUCCESS;
    }

    protected function kill(int $signal = 0): void
    {
        if ($signal && $this->pid) {
            if (SIGKILL === $signal) {
                ProcessHelper::kill($this->pid, $signal);
            } else {
                posix_kill($this->pid, $signal);
            }
        }

        // Cleaning handles
        if (!empty($this->pipes)) {
            foreach ($this->pipes as $h) {
                if ($h) {
                    fclose($h);
                }
            }
            $this->pipes = [];
        }
        if (!empty($this->proc)) {
            proc_close($this->proc);
            $this->proc = null;
        }

        $this->pid = 0;
    }

    protected function logMessage(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}('[{group}] [{host}] [{display}] '.$message, array_merge([
                'group' => $this->groupConfig->getName(),
                'host' => $this->host,
                'display' => $this->display,
            ], $context));
        } else {
            foreach ($context as $key => $value) {
                $message = str_replace('{'.$key.'}', $value, $message);
            }
            echo "{level:{$level}}[{$this->display}] {$message}\n";
            flush();
        }
    }

    /**
     * Build the shell command to be executed for this process.
     *
     * @return string the shell command
     */
    protected function buildShellCommand(): string
    {
        $params = escapeshellarg(json_encode($this->buildParams()));

        if ($this->config->getBinPath()) {
            $bin = $this->config->getBinPath();
        } elseif ($this->groupConfig->getBinPath()) {
            $bin = $this->groupConfig->getBinPath();
        } else {
            $bin = PHP_BINARY;
        }

        return $bin.' '.$this->groupConfig->getCommand().' '.$params;
    }
}
