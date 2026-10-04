<?php

namespace Tests\Fakes;

use Native\Mobile\Network;

class FakeNetwork extends Network
{
    public bool $connected = true;

    public function status(): ?object
    {
        return (object) ['connected' => $this->connected, 'type' => $this->connected ? 'wifi' : 'none'];
    }
}
