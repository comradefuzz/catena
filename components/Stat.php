<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 17.05.16
 * Time: 16:40
 */

namespace ladno\catena\components;


use ladno\catena\Module;
use yii\base\Component;
use yii\redis\Connection;

class Stat extends Component
{

    const STAT_TYPE_PROCESSED = 'processed';
    const STAT_TYPE_FAILED = 'failed';

    /**
     * @var Module
     */
    protected $_module;
    /**
     * @var Connection
     */
    protected $_redis;


    public function init()
    {
        $this->_module = Module::getInstance();
        $this->_redis = $this->_module->redis;
    }

    public function getQueueStat($queue, $stat)
    {
        return (int)$this->_redis->get($this->getStatKey($queue, $stat));
    }

    public function getWorkerStat($id, $stat)
    {
        return (int)$this->_redis->get($this->getWorkerStatKey($id, $stat));
    }

    public function clearQueueStat($queue)
    {
        $this->_redis->del($this->getStatKey($queue, self::STAT_TYPE_PROCESSED));
        $this->_redis->del($this->getStatKey($queue, self::STAT_TYPE_FAILED));
    }

    public function clearWorkerStat($id)
    {
        $this->_redis->del($this->getWorkerStatKey($id, self::STAT_TYPE_PROCESSED));
        $this->_redis->del($this->getWorkerStatKey($id, self::STAT_TYPE_FAILED));
    }

    public function clearWorkersStat()
    {
        foreach ($this->_redis->keys("stat:worker:*") as $key)
        {
            $this->_redis->del($key);
        }
    }

    public function queueStatIncrease($queue, $stat, $delta = 1)
    {
        $this->_redis->incrby($this->getStatKey($queue, $stat), $delta);
    }

    public function queueStatDecrease($queue, $stat, $delta = 1)
    {
        $this->_redis->incrby($this->getStatKey($queue, $stat), $delta);
    }


    public function workerStatIncrease($worker, $stat, $delta = 1)
    {
        $this->_redis->incrby($this->getWorkerStatKey($worker, $stat), $delta);
    }

    public function workerStatDecrease($worker, $stat, $delta = 1)
    {
        $this->_redis->decrby($this->getWorkerStatKey($worker, $stat), $delta);
    }

    protected function getStatKey($item, $stat)
    {
        return 'stat:' . $stat . ':' . $item;
    }

    protected function getWorkerStatKey($item, $stat)
    {
        return 'stat:worker:' . $stat . ':' . $item;
    }

}

