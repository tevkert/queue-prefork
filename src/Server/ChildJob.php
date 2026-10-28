<?php

namespace Tevkert\QueuePrefork\Server;

/** What a forked child runs once it is back at the template script's top level. */
final class ChildJob
{
    /** @param list<string> $argv */
    public function __construct(
        public readonly string $entry,
        public readonly string $script,
        public readonly array $argv,
        public readonly string $prependFile,
    ) {}
}
