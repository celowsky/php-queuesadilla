<?php

namespace josegonzalez\Queuesadilla\Engine;

use josegonzalez\Queuesadilla\Engine\Base;
use Redis;
use RedisException;

class RedisEngine extends Base
{
    protected $baseConfig = [
        'database' => null,
        'pass' => false,
        'persistent' => true,
        'port' => 6379,
        'queue' => 'default',
        'host' => '127.0.0.1',
        'timeout' => 0,
    ];

    /**
     * {@inheritDoc}
     */
    public function connect()
    {
        $return = false;
        $connectMethod = 'connect';
        if (!empty($this->settings['persistent'])) {
            $connectMethod = 'pconnect';
        }

        try {
            $this->connection = $this->redisInstance();
            if ($this->connection) {
                $return = $this->connection->$connectMethod(
                    $this->settings['host'],
                    $this->settings['port'],
                    (int)$this->settings['timeout']
                );
            }
        } catch (RedisException $e) {
            return false;
        }

        if ($return && $this->settings['database'] !== null) {
            $return = $this->connection->select((int)$this->settings['database']);
        }

        if ($return && $this->settings['pass']) {
            $return = $this->connection->auth($this->settings['pass']);
        }

        return $return;
    }

    /**
     * {@inheritDoc}
     */
    public function delete($item)
    {
        if (!is_array($item) || !isset($item['id'])) {
            return false;
        }

        $script = $this->getRemoveScript();
        $exists = $this->ensureRemoveScript();
        if (!$exists) {
            return false;
        }

        return (bool)$this->connection()->evalSha(sha1($script), [
            $item['queue'],
            rand(),
            $item['id'],
        ], 3);
    }

    /**
     * {@inheritDoc}
     */
    public function pop($options = [])
    {
        $queue = $this->setting($options, 'queue');
        $item = $this->connection()->lpop('queue:' . $queue);
        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    /**
     * {@inheritDoc}
     */
    public function push($class, $vars = [], $options = [])
    {
        $queue = $this->setting($options, 'queue');
        $this->requireQueue($options);

        $jobId = $this->jobId();
        return (bool)$this->connection()->rpush('queue:' . $queue, json_encode([
            'id' => (int)$jobId,
            'class' => $class,
            'vars' => $vars,
            'queue' => $queue,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function queues()
    {
        return $this->connection()->smembers('queues');
    }

    /**
     * {@inheritDoc}
     */
    public function release($item, $options = [])
    {
        if (!is_array($item) || !isset($item['id'])) {
            return false;
        }

        $queue = $this->setting($options, 'queue');
        $this->requireQueue($options);

        return $this->connection()->rpush('queue:' . $queue, json_encode($item));
    }

    protected function ensureRemoveScript()
    {
        $script = $this->getRemoveScript();
        $exists = $this->connection()->script('exists', sha1($script));
        if (!empty($exists[0])) {
            return $exists[0];
        }
        return $this->connection()->script('load', $script);
    }

    protected function getRemoveScript()
    {
        $script = <<<EOF
-- KEYS[1]: The queue to work on
-- KEYS[2]: A random number
-- KEYS[3]: The id of the message to delete
local originalQueue = 'queue:'..KEYS[1]
local tempQueue = originalQueue..':temp:'..KEYS[2]
local requeueQueue = tempQueue..':requeue'
local deleted = false
local itemId = tonumber(KEYS[3])
while true do
    local str = redis.pcall('rpoplpush', originalQueue, tempQueue)
    if str == nil or str == '' or str == false then
        break
    end

    local item = cjson.decode(str)
    if item["id"] == itemId then
        deleted = true
        break
    else
        redis.pcall('rpoplpush', tempQueue, requeueQueue)
    end
end

while true do
    local str = redis.pcall('rpoplpush', requeueQueue, originalQueue)
    if str == nil or str == '' or str == false then
        break
    end
end

redis.pcall('del', requeueQueue)
redis.pcall('del', tempQueue)
return deleted
EOF;
        return trim($script);
    }

    protected function redisInstance()
    {
        return new Redis();
    }

    protected function requireQueue($options)
    {
        $queue = $this->setting($options, 'queue');
        $this->connection()->sadd('queues', $queue);
    }
}
