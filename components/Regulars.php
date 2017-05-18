<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 06.07.16
 * Time: 13:57
 */

namespace ladno\catena\components;


use ladno\catena\Module;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\redis\Connection;

/**
 * Class Regulars
 * @property mixed jobs
 * @package ladno\catena\components
 */
class Regulars extends Component
{
    const DEFAULT_JOB_INTERVAL = 5;
    const MAX_EXECUTION_TIME = 60;

    public $interval = 5;
    protected $_jobs = [];

    /**
     * @var Connection
     */
    protected $_redis;

    /**
     * @var Module
     */
    protected $_module;


    public function init()
    {
        $this->_module = Module::getInstance();
        $this->_redis = $this->_module->redis;

        $this->registerJobs($this->_module->regularJobs);
    }

    /**
     * Register checker daemon
     * @return bool
     */
    public function register()
    {
        if ($pid = $this->getPid()) {
            if (posix_kill($pid, SIG_BLOCK)) {
                return false;
            }
        }

        $this->_redis->set($this->pidKey(), getmypid());
        return true;
    }

    /**
     * Unregister checker daemon
     */
    public function unregister()
    {
        $this->_redis->del($this->pidKey());
        $this->cleanup();
    }

    public function getPid()
    {
        return $this->_redis->get($this->pidKey());
    }

    protected function pidKey()
    {
        return "regulars:" . gethostname();
    }

    public function isActive()
    {
        if (!$pid = $this->getPid()) {
            return false;
        }

        return posix_kill($pid, SIG_BLOCK);
    }

    public function stop()
    {
        $pid = $this->getPid();
        if (!empty($pid)) {
            posix_kill($this->getPid(), SIGTERM);
        }
    }

    /**
     * Dynamically register jobs
     *
     * @param $data
     */
    public function registerJobs($data)
    {
        foreach ($data as $item) {
            $class = empty($item['class']) ? array_shift($item) : $item['class'];
            $args = empty($item['args']) ? [] : $item['args'];
            $queue = empty($item['queue']) ? $this->_module->queue->defaultQueue : $item['queue'];
            $interval = empty($item['interval']) ? static::DEFAULT_JOB_INTERVAL : $item['interval'];
            $ttl = empty($item['ttl']) ? 0 : $item['ttl'];
            $maxTime = empty($item['maxTime']) ? static::MAX_EXECUTION_TIME : $item['maxTime'];
            $descriptor = $class . '-' . (!empty($item['descriptor']) ? $item['descriptor'] : 'default');
            $this->_jobs[$descriptor] = [
                'class' => $class,
                'queue' => $queue,
                'interval' => $interval,
                'args' => $args,
                'descriptor' => $descriptor,
                'ttl' => $ttl,
                'maxTime' => $maxTime,
            ];
        }
    }

    /**
     * @return array
     */
    public function getJobs()
    {
        return $this->_jobs;
    }

    public function cleanup()
    {
        $this->_redis->del($this->getJobsKey());
    }

    public function getJobData($descriptor)
    {
        if ($data = $this->_redis->hget($this->getJobsKey(), md5($descriptor))) {
            $data = unserialize($data);
        } else {
            $data = [];
        }

        return array_merge([
            'token' => '',
            'updatedAt' => 0,
        ], $data);
    }

    public function updateJobData($descriptor, $token)
    {
        $this->_redis->hset($this->getJobsKey(), md5($descriptor), serialize([
            'token' => $token,
            'updatedAt' => time()
        ]));
    }

    protected function getJobsKey()
    {
        return "regulars:" . gethostname() . ':jobs';
    }
}