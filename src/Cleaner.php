<?php

namespace Tarantool\SymfonyLock;

use Tarantool\Client\Client;
use InvalidArgumentException;

class Cleaner
{
    use OptionalConstructor;

    /**
     * Dropped rows counter limit
     */
    protected int $limit = 100;

    /**
     * Space name
     */
    protected string $space = 'lock';

    protected function validateOptions()
    {
        if ($this->limit <= 0) {
            $message = sprintf(
                'Limit expects a strictly positive. Got %d.',
                $this->limit
            );
            throw new InvalidArgumentException($message);
        }
        if ($this->space == '') {
            throw new InvalidArgumentException("Space should be defined");
        }
    }

    public function process(): int
    {
        $script = <<<LUA
        local limit, timestamp = ...
        local counter = 0
        box.begin()
        box.space.$this->space.index.expire:pairs()
            :take_while(function(tuple) return counter < limit end)
            :take_while(function(tuple) return tuple.expire <= timestamp end)
            :each(function(tuple)
                box.space.$this->space:delete( tuple.key )
                counter = counter + 1
            end)
        box.commit()
        return counter
        LUA;

        [$counter] = $this->client->evaluate($script, $this->limit, microtime(true));

        return $counter;
    }
}
