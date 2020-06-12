<?php

namespace giudicelli\DistributedArchitecture\Master\Handlers\Local;

use giudicelli\DistributedArchitecture\Config\GroupConfigInterface;
use giudicelli\DistributedArchitecture\Config\ProcessConfigInterface;
use giudicelli\DistributedArchitecture\Helper\ProcessHelper;
use giudicelli\DistributedArchitecture\Master\Handlers\AbstractProcess;
use giudicelli\DistributedArchitecture\Master\LauncherInterface;

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
    public static function instanciate(LauncherInterface $launcher, GroupConfigInterface $groupConfig, ProcessConfigInterface $config, int $idStart, int $groupIdStart, int $groupCount): array
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
            $children[] = new $class($id, $groupId, $groupCount, $groupConfig, $config, $launcher);
        }

        return $children;
    }

    /**
     * {@inheritdoc}
     */
    public static function willStartCount(ProcessConfigInterface $config): int
    {
        return $config->getInstancesCount();
    }

    /**
     * {@inheritdoc}
     */
    protected function isEventCompatible(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function run(): bool
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

            return false;
        }

        $status = proc_get_status($this->proc);
        if (empty($status['running']) || empty($status['pid'])) {
            $this->kill();

            return false;
        }

        $this->pid = $status['pid'];

        if ($this->config->getPriority()) {
            @pcntl_setpriority($this->config->getPriority(), $this->pid);
        } elseif ($this->groupConfig->getPriority()) {
            @pcntl_setpriority($this->groupConfig->getPriority(), $this->pid);
        }

        stream_set_blocking($this->pipes[1], 0);
        stream_set_read_buffer($this->pipes[1], 0);

        return true;
    }

    /**
     * {@inheritdoc}
     */
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
            $status = proc_get_status($this->proc);
            if (empty($status['running'])) {
                return self::READ_FAILED;
            }

            return self::READ_EMPTY;
        }

        return self::READ_SUCCESS;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendSignal(int $signal): void
    {
        if (!$this->pid) {
            return;
        }

        if (SIGKILL === $signal) {
            ProcessHelper::kill($this->pid, $signal);
        } else {
            // Sometimes proc_open actually forks a bash instead of the asked binary
            // here we try to send the signal to the pid if it matches getBinPath()
            // else to all its children that match getBinPath()
            if (!ProcessHelper::killBinary($this->getBinPath(), $this->pid, $signal)) {
                // The binary could not be matched, just send the signal
                // to the pid we have
                posix_kill($this->pid, $signal);
            }
        }
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

    /**
     * Build the shell command to be executed for this process.
     *
     * @return string the shell command
     */
    protected function buildShellCommand(): string
    {
        return $this->getShellCommand($this->buildParams()).' 2>&1';
    }
}
