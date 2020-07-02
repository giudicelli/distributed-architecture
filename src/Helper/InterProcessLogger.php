<?php

namespace giudicelli\DistributedArchitecture\Helper;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The class allows processes to send each other logs.
 *
 * @author Frédéric Giudicelli
 *
 * @internal
 */
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
    public function __construct(bool $local = false, ?LoggerInterface $logger = null)
    {
        $this->local = $local;
        $this->logger = $logger;
    }

    /**
     * Allows a slave process to directy send a serialized log to the master process.
     *
     * @param mixed   $level
     * @param string  $message
     * @param mixed[] $context
     */
    public static function sendLog($level, $message, array $context = [])
    {
        $buffers = self::suspendBuffering();
        echo self::serializeLog($level, $message, $context)."\n";
        self::resumeBuffering($buffers);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = [])
    {
        if (!$this->local) {
            self::sendLog($level, $message, $context);

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
        $message = trim(join(' ', $prefix).' '.$message);

        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        } else {
            echo $this->interpolate($message, $context).PHP_EOL;
        }
    }

    /**
     * Serialize a log.
     *
     * @return string the serialized log
     */
    protected static function serializeLog(string $level, string $message, array $context = []): string
    {
        return self::TOKEN.serialize(['level' => $level, 'message' => $message, 'context' => $context]);
    }

    /**
     * Check if a string is a serialized log.
     */
    protected static function isSerializedMessage(string $line): bool
    {
        return self::TOKEN === substr($line, 0, self::TOKEN_LENGTH);
    }

    /**
     * Unserialize a log.
     */
    protected static function unserializeLog(string $line): ?array
    {
        if (!self::isSerializedMessage($line)) {
            return null;
        }
        $line = substr($line, self::TOKEN_LENGTH);
        $log = @unserialize($line);
        if (empty($log['level']) || !isset($log['message']) || !isset($log['context'])) {
            return null;
        }

        return $log;
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * @author PHP Framework Interoperability Group
     */
    protected function interpolate(string $message, array $context): string
    {
        if (false === strpos($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $val) {
            if (null === $val || is_scalar($val) || (\is_object($val) && method_exists($val, '__toString'))) {
                $replacements["{{$key}}"] = $val;
            } elseif ($val instanceof \DateTimeInterface) {
                $replacements["{{$key}}"] = $val->format(\DateTime::RFC3339);
            } elseif (\is_object($val)) {
                $replacements["{{$key}}"] = '[object '.\get_class($val).']';
            } else {
                $replacements["{{$key}}"] = '['.\gettype($val).']';
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Suspends all buffering and returns the active buffers.
     *
     * @return string[] The buffers
     */
    protected static function suspendBuffering(): array
    {
        $buffers = [];
        while (ob_get_level()) {
            $buffers[] = ob_get_clean();
        }

        return $buffers;
    }

    /**
     * Resume buffering and set its previous buffers.
     *
     * @param string[] $buffers The buffers as returned by suspendBuffering
     */
    protected static function resumeBuffering(array $buffers): void
    {
        $buffers = array_reverse($buffers);
        foreach ($buffers as $buffer) {
            ob_start(function () { return ''; });
            echo $buffer;
        }
    }
}
