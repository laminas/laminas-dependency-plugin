<?php

declare(strict_types=1);

namespace LaminasTest\DependencyPlugin\TestAsset;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

use function array_keys;
use function array_reduce;
use function implode;
use function sprintf;
use function strpos;

class IOWriteExpectations implements IteratorAggregate
{
    /**
     * @var array
     * @psalm-var array<string, bool>
     */
    private $messages = [];

    /**
     * @param string[] $messages
     * @psalm-param array<array-key, string> $messages
     */
    public function __construct(array $messages)
    {
        foreach ($messages as $message) {
            $this->messages[$message] = false;
        }
    }

    public function __toString(): string
    {
        $unseenMessages = [];
        foreach ($this->messages as $message => $seen) {
            if ($seen) {
                continue;
            }
            $unseenMessages[] = sprintf('- %s', $message);
        }

        return implode("\n", $unseenMessages);
    }

    /**
     * @psalm-return Traversable<array-key, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_keys($this->messages));
    }

    public function matches(string $message): bool
    {
        foreach ($this as $expectedMessage) {
            if (false !== strpos($message, $expectedMessage)) {
                $this->messages[$expectedMessage] = true;
                return true;
            }
        }

        return false;
    }

    public function foundAll(): bool
    {
        return array_reduce($this->messages, function (bool $found, bool $flag): bool {
            return $found && $flag;
        }, true);
    }
}
