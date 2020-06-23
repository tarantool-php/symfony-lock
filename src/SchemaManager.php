<?php

namespace Tarantool\SymfonyLock;

use Tarantool\Client\Client;
use InvalidArgumentException;

class SchemaManager
{
    use OptionalConstructor;

    /**
     * what engine should be used
     */
    protected string $engine = 'memtx';

    /**
     * space name
     */
    protected string $space = 'lock';

    public function validateOptions()
    {
        if ($this->engine == '') {
            throw new InvalidArgumentException("Engine should be defined");
        }
        if ($this->space == '') {
            throw new InvalidArgumentException("Space should be defined");
        }
    }
    /**
     * setup database schema
     */
    public function setup()
    {
        $this->client->evaluate('box.schema.create_space(...)', $this->space, [
            'engine' => $this->engine,
            'if_not_exists' => true,
            'format' => [
                [
                    'name' => 'key',
                    'type' => 'string',
                ],
                [
                    'name' => 'token',
                    'type' => 'string',
                ],
                [
                    'name' => 'expire',
                    'type' => 'number',
                ],
            ],
        ]);

        $this->client->call("box.space.{$this->space}:create_index", "key", [
            'type' => 'hash',
            'if_not_exists' => true,
            'unique' => true,
            'parts' => ['key'],
        ]);

        $this->client->call("box.space.{$this->space}:create_index", "token_key", [
            'type' => 'hash',
            'if_not_exists' => true,
            'unique' => true,
            'parts' => ['token', 'key'],
        ]);

        $this->client->call("box.space.{$this->space}:create_index", "expire", [
            'type' => 'tree',
            'if_not_exists' => true,
            'unique' => false,
            'parts' => ['expire'],
        ]);
    }

    /**
     * rollback lock store schema
     */
    public function tearDown()
    {
        $script = <<<LUA
        if box.space.$this->space then
            box.space.$this->space:drop()
        end
        LUA;

        $this->client->evaluate($script);
    }
}
