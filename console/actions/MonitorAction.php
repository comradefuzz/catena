<?php
/**
 * Created by PhpStorm.
 * User: julia
 * Date: 30.09.16
 * Time: 15:45
 */

namespace ladno\catena\console\actions;

use ladno\catena\components\Redis;
use ladno\catena\Module;
use yii\base\Action;
use yii\log\Logger;

class MonitorAction extends Action
{
    protected $stop = false;

    /**
     * @var Module
     */
    protected $_module;
    /**
     * @var Redis
     */
    protected $_redis;


    public function init()
    {
        $this->_module = $this->controller->module;
        $this->listenSignals();
    }

    /**
     * Workers health checker
     *
     * @param int $sleep
     */
    public function run($sleep = 0)
    {
        if (empty($sleep)) {
            $sleep = $this->_module->respawnInterval;
        }

        $this->setProcessTitle();

        if (!$this->_module->monitor->register()) {
            return;
        }

        while (!$this->stop) {
            $this->_module->worker->clearDeadWorkers();

            $workers = $this->_module->worker->getWorkers();
            foreach ($this->_module->workers as $group => $item) {
                $delta = $item['count'] - count($workers[$group]);
                if ($delta > 0 ) {
                    $this->log("Restoring {$delta} workers in group $group");
                    $this->controller->actionStartWorkers($group);
                }
            }

            // Checking signal handlers
            pcntl_signal_dispatch();
            sleep($sleep);
        }

        $this->_module->monitor->unregister();
    }


    protected function listenSignals()
    {
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
    }

    protected function setProcessTitle($title = '')
    {
        cli_set_process_title($this->controller->module->id
            . " monitor"
            . ($title ? " ({$title})" : '')
        );
    }

    protected function shutdown()
    {
        $this->stop = true;
        $this->setProcessTitle("shutting down");
    }

    protected function log($message, $level = Logger::LEVEL_INFO)
    {
        Module::log($message, $level, 'monitor');
        \Yii::getLogger()->flush(true);
    }
}
