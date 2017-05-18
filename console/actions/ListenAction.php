<?php
/**
 * Created by PhpStorm.
 * User: julia
 * Date: 30.09.16
 * Time: 15:45
 */

namespace ladno\catena\console\actions;

use ladno\catena\components\Queue;
use ladno\catena\components\Stat;
use ladno\catena\exceptions\DontPerformException;
use ladno\catena\models\JobStatus;
use ladno\catena\models\QueueItem;
use ladno\catena\Module;
use yii\base\Action;
use yii\base\Exception;
use yii\log\Logger;

class ListenAction extends Action
{
    const DEFAULT_SLEEP = 5;
    /**
     * @var int Bytes
     */
    public $memoryLimit;


    protected $stop = false;
    protected $pause = false;

    protected $workerId;

    /**
     * @var Module
     */
    protected $_module;
    /**
     * @var Queue
     */
    protected $_queue;

    /**
     * @var Stat
     */
    protected $_stat;

    protected $groupCode;
    protected $processTitle;


    public function init()
    {
        $this->_module = $this->controller->module;
        $this->_queue = $this->controller->module->queue;
        $this->_stat = $this->controller->module->stat;

        $this->listenSignals();
    }

    /**
     * Queue listener
     *
     * @param string $queue
     * @param string $group
     * @param int $sleep
     * @param int $memoryLimit
     */
    public function run($queue = '', $group = null, $sleep = 0, $memoryLimit = 0)
    {

        if (empty($queue)) {
            $queue = $this->_queue->defaultQueue;
        }

        if (empty($sleep)) {
            $sleep = static::DEFAULT_SLEEP;
        }

        $this->memoryLimit = $memoryLimit;
        $this->groupCode = $group;
        $this->processTitle = $queue;
        $this->setProcessTitle();

        $queue = explode(",", $queue);
        $queues = $queuesBuffer = $emptyQueues = [];

        $this->workerId = $this->_module->worker->register($queue, $group);

        while (!$this->stop) {
            if (!$this->pause) {
                if (empty($queuesBuffer)) {
                    $queues = !in_array('*', $queue) ? $queue : $this->_queue->getQueues();
                    $queuesBuffer = $queues;
                    $emptyQueues = [];
                }

                $currentQueue = array_shift($queuesBuffer);
                if ($currentQueue) {
                    $this->trace("Reading queue {$currentQueue}");
                    $item = $this->_queue->pop($currentQueue);
                    if (!is_null($item)) {
                        $this->perform($item);
                    } else {
                        $emptyQueues[$currentQueue] = $currentQueue;
                    }
                }
            }

            // Checking signal handlers
            pcntl_signal_dispatch();

            $this->checkMemory();

            if ($this->pause || count($emptyQueues) == count($queues)) {
                $this->trace("Sleeping $sleep seconds");
                sleep($sleep);
            }
        }

        $this->_module->worker->unregister();
    }

    protected function perform(QueueItem $item)
    {
        $job = $item->job;
        $className = get_class($job);
        $this->trace("Performing {$item->id} ({$className})");

        $isTrackedJob = !is_null($this->_queue->getJobStatus($item->id));

        if ($isTrackedJob) {
            $this->_queue->setJobStatus($item->id, JobStatus::STATUS_WORKING);
        }

        if (!$job->validate()) {

            throw new DontPerformException(
                'Invalid job arguments '
                . PHP_EOL . '  * ' . implode(PHP_EOL . '  * ', $job->getFirstErrors())
            );
        }

        try {
            if (!empty($item->ttl) && (($item->createdAt + $item->ttl) <= time())) {
                throw new DontPerformException('TTL expired');
            }

            $job->setUp();
            $job->perform();
            $job->tearDown();

            if ($isTrackedJob) {
                $this->_queue->setJobStatus($item->id, JobStatus::STATUS_DONE);
            }

            $this->_stat->queueStatIncrease($item->queue, 'processed');
            $this->_stat->workerStatIncrease($this->workerId, 'processed');

            $this->trace("Job {$item->id} ({$className}) is done");

            \Yii::getLogger()->flush(true);

        } catch (DontPerformException $e) {
            $this->log(
                "Job {$className} could not set up: " . $e->getMessage() . PHP_EOL
                . print_r($job->attributes, true),
                Logger::LEVEL_WARNING
            );
            $this->_stat->queueStatIncrease($item->queue, 'failed');
            $this->_stat->workerStatIncrease($this->workerId, 'failed');
        } catch (Exception $e) {
            $this->log(
                "Job {$className} failed: " . $e->getMessage() . PHP_EOL
                . print_r($job->attributes, true) . PHP_EOL
                . $e->getTraceAsString(),
                Logger::LEVEL_ERROR
            );
            $this->_stat->queueStatIncrease($item->queue, 'failed');
            $this->_stat->workerStatIncrease($this->workerId, 'failed');
        }
    }

    protected function listenSignals()
    {
        pcntl_signal(SIGTSTP, [$this, 'pause']);
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGCONT, [$this, 'resume']);
    }

    protected function checkMemory()
    {
        if (!empty($this->memoryLimit)) {
            $memory = memory_get_usage();
            if ($memory > $this->memoryLimit) {
                $this->log("Memory limit exceeded ({$this->human_filesize($memory)}), shutting down", Logger::LEVEL_WARNING);
                $this->shutdown();
            }
        }
    }


    protected function human_filesize($bytes, $decimals = 2)
    {
        $size = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    protected function setProcessTitle($title = '')
    {
        cli_set_process_title($this->controller->module->id
            . "[{$this->groupCode}]: {$this->processTitle}"
            . ($title ? " ({$title})" : '')
        );
    }

    protected function pause()
    {
        $this->pause = true;
        $this->setProcessTitle("paused since " . date('Y-m-d H:i:s'));
    }

    protected function resume()
    {
        $this->pause = false;
        $this->setProcessTitle("");
    }

    protected function shutdown()
    {
        $this->stop = true;
        $this->setProcessTitle("shutting down");
    }

    protected function log($message, $level = Logger::LEVEL_INFO)
    {
        Module::log($message, $level);
        \Yii::getLogger()->flush(true);
    }

    protected function trace($message)
    {
        if (Module::getInstance()->verbose) {
            Module::trace($message);
            \Yii::getLogger()->flush(true);
        }
    }
}
