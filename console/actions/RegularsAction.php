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
use yii\base\Exception;
use yii\log\Logger;

class RegularsAction extends Action
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
     * Regular jobs scheduler
     *
     * @param int $sleep
     */
    public function run($sleep = 0)
    {
        if (empty($sleep)) {
            $sleep = $this->_module->regularsCheckInterval;
        }

        $this->setProcessTitle();

        if (!$this->_module->regulars->register()) {
            return;
        }

        while (!$this->stop) {
            foreach ($this->_module->regulars->jobs as $job) {
                $data = $this->_module->regulars->getJobData($job['descriptor']);
                if (($data['updatedAt'] + $job['interval']) >= time()) {
                    continue;
                }

                if (!empty($data['token'])) {
                    if ($this->_module->queue->isActualJob($data['token'])) {
                        continue;
                    }
                    $this->_module->queue->deleteJobStatus($data['token']);
                }

                try {
                    $token = $this->_module->queue->push(
                        new $job['class']($job['args']),
                        $job['queue'],
                        true,
                        $job['interval']
                    );
                    $this->_module->regulars->updateJobData($job['descriptor'], $token);

                } catch (Exception $e) {
                    $this->log("Failed to enqueue regular job {$job['descriptor']}" . PHP_EOL
                        . $e->getMessage() . PHP_EOL
                        . $e->getTraceAsString(),
                        Logger::LEVEL_ERROR);
                }
            }

            // Checking signal handlers
            pcntl_signal_dispatch();
            sleep($sleep);
        }

        $this->_module->regulars->unregister();
    }


    protected function listenSignals()
    {
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
    }

    protected function setProcessTitle($title = '')
    {
        cli_set_process_title($this->controller->module->id
            . " regulars"
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
        \yii\helpers\Console::output($message);
        Module::log($message, $level, 'regulars');
        \Yii::getLogger()->flush(true);
    }
}
