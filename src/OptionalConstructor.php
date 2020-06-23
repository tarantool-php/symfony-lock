<?php

namespace Tarantool\SymfonyLock;

use Tarantool\Client\Client;

trait OptionalConstructor
{
    private Client $client;

    public function __construct(Client $client, array $options = [])
    {
        foreach ($options as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $options[$k];
            }
        }

        $this->client = $client;

        $this->validateOptions();
    }

    abstract protected function validateOptions();
}
