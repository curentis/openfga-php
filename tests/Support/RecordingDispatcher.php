<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[\Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
