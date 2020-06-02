<?php

namespace giudicelli\DistributedArchitecture\Helper;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class InterProcessLogger extends AbstractLogger
{
    protected const TOKEN = 'InterProcessLogger:';
    protected const TOKEN_LENGTH = 19;

    protected $local;

    protected $logger;

    /**
     * @param bool                 $local  Is the logger instanciated on the main master?
     * @param null|LoggerInterface $logger When local is true, the actual final logger
     */
    public function __construct(bool $local, ?LoggerInterface $logger = null)
    {
        $this->local = $local;
        $this->logger = $logger;
    }

    public function log($level, $message, array $context = [])
    {
        if (!$this->local) {
            echo self::serializeLog($level, $message, $context)."\n";

            return;
        }
        if (self::isSerializedMessage($message)) {
            $log = self::unserializeLog($message);
            if (!empty($log)) {
                $subContext = $log['context'];
                if (isset($context['host'])) {
                    $host = $context['host'];
                    $context = array_merge($context, $subContext);
                    $context['host'] = $host;
                } else {
                    $context = array_merge($context, $subContext);
                }
                $this->log($log['level'], $log['message'], $context);

                return;
            }
        }

        $prefix = [];
        $order = ['group', 'host', 'display'];
        foreach ($order as $key) {
            if (isset($context[$key])) {
                $prefix[] = '['.$context[$key].']';
                unset($context[$key]);
            }
        }
        $message = join(' ', $prefix).' '.$message;

        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        } else {
            foreach ($context as $key => $value) {
                $message = str_replace('{'.$key.'}', $value, $message);
            }
            echo "{$message}\n";
        }
    }

    protected static function serializeLog(string $level, string $message, array $context = []): string
    {
        return self::TOKEN.json_encode(['level' => $level, 'message' => $message, 'context' => $context]);
    }

    protected static function isSerializedMessage(string $line): bool
    {
        return self::TOKEN === substr($line, 0, self::TOKEN_LENGTH);
    }

    protected static function unserializeLog(string $line): ?array
    {
        if (!self::isSerializedMessage($line)) {
            return null;
        }
        $line = substr($line, self::TOKEN_LENGTH);
        $log = @json_decode($line, true);
        if (empty($log['level']) || !isset($log['message']) || !isset($log['context'])) {
            return null;
        }

        return $log;
    }
}
