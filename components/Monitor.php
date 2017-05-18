<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 17.05.16
 * Time: 16:40
 */

namespace ladno\catena\components;


use ladno\catena\Module;
use Prophecy\Argument\Token\ExactValueToken;
use yii\base\Component;
use yii\base\Exception;
use yii\redis\Connection;

class Monitor extends Component
{

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

    /**
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

    public function unregister()
    {
        $this->_redis->del($this->pidKey());
    }

    public function getPid()
    {
        return $this->_redis->get($this->pidKey());
    }

    protected function pidKey()
    {
        return "monitor:" . gethostname();
    }

    public function stop()
    {
        return posix_kill($this->getPid(), SIGTERM);
    }

    public function kill()
    {
        posix_kill($this->getPid(), SIGKILL);

        $this->unregister();
    }

    /**
     * Check if monitor is running
     *
     * @return bool
     */
    public function isActive()
    {
        $pid = $this->getPid();
        if (!$pid) {
            return false;
        }

        return posix_kill($pid, SIG_BLOCK);
    }
}

