<?php

namespace giudicelli\DistributedArchitecture\Helper;

/** @internal */
final class ProcessHelper
{
    public static function childrenProcs(int $pid): ?array
    {
        $output = [];
        exec("ps axo ppid,pid | awk '\$1 ==  {$pid} { print \$2 }'", $output, $ret);
        if ($ret) {
            return null;
        }

        return $output;
    }

    public static function kill(int $pid, int $signal): void
    {
        $children = self::childrenProcs($pid);
        if (!$children) {
            posix_kill($pid, $signal);

            return;
        }
        foreach ($children as $cpid) {
            if ($cpid == $pid) {
                continue;
            }
            self::kill($cpid, $signal);
        }
        posix_kill($pid, $signal);
    }
}
