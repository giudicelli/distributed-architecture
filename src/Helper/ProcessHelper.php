<?php

namespace giudicelli\DistributedArchitecture\Helper;

/**
 * @author Frédéric Giudicelli
 *
 * @internal
 */
final class ProcessHelper
{
    /**
     * Get the children processes of a pid.
     *
     * @param int $pid The pid
     *
     * @return null|array<int> The children pid or null if there are none
     */
    public static function childrenProcs(int $pid): ?array
    {
        $output = [];
        exec("ps axo ppid,pid | awk '\$1 == {$pid} { print \$2 }'", $output, $ret);
        if ($ret) {
            return null;
        }

        return $output;
    }

    /**
     * Get all the children processes of a pid.
     *
     * @param int $pid The pid
     *
     * @return null|array<int> The children pid or null if there are none
     */
    public static function allChildrenProcs(int $pid): ?array
    {
        $queue = [$pid];
        $done = [];
        $allChildren = [];
        do {
            $pid = array_shift($queue);
            $done[] = $pid;
            $children = self::childrenProcs($pid);
            if ($children) {
                foreach ($children as $child) {
                    if (in_array($child, $done)) {
                        continue;
                    }
                    $allChildren[] = $child;
                    $queue[] = $child;
                }
            }
        } while (!empty($queue));

        return empty($allChildren) ? null : $allChildren;
    }

    /**
     * Recursively kill a process and all its children.
     *
     * @param int $pid    The pid
     * @param int $signal The signal to send
     */
    public static function kill(int $pid, int $signal): void
    {
        $children = self::allChildrenProcs($pid);
        if (!$children) {
            posix_kill($pid, $signal);

            return;
        }
        rsort($children);
        foreach ($children as $cpid) {
            if ($cpid == $pid) {
                continue;
            }
            posix_kill($cpid, $signal);
        }
        posix_kill($pid, $signal);
    }

    /**
     * Kill a process if its command matches a value else kill all its direct children whose command matches the value.
     *
     * @param string $binary The command to match
     * @param int    $pid    The pid
     * @param int    $signal The signal to send
     *
     * @return bool true if the binary was found else false
     */
    public static function killBinary(string $binary, int $pid, int $signal): bool
    {
        $cmd = `ps -o cmd $pid | tail -n 1 | awk '{print \$1}'`;
        if (trim($cmd) === $binary) {
            posix_kill($pid, $signal);

            return true;
        }
        $children = self::childrenProcs($pid);
        if (!$children) {
            posix_kill($pid, $signal);

            return false;
        }
        $ret = false;
        foreach ($children as $cpid) {
            if ($cpid == $pid) {
                continue;
            }
            if (self::killBinary($binary, $cpid, $signal)) {
                $ret = true;
            }
        }

        return $ret;
    }
}
