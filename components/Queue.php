<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 17.05.16
 * Time: 16:40
 */

namespace ladno\catena\components;


use ladno\catena\models\BaseJob;
use ladno\catena\models\JobStatus;
use ladno\catena\models\QueueItem;
use ladno\catena\Module;
use yii\base\Component;
use yii\redis\Connection;

class Queue extends Component
{

    public $defaultQueue = 'default';

    protected $_hostname;

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
     * Push job to queue
     *
     * @param BaseJob $job Job instance
     * @param string $queue Queue identifier
     * @param bool $trackStatus Enables job status tracking
     * @param int $ttl TTL in seconds, expired jobs will be skipped
     * @return string Job id
     */
    public function push(BaseJob $job, $queue = '', $trackStatus = false, $ttl = 0)
    {
        if (empty($queue)) {
            $queue = $this->defaultQueue;
        }

        $item = new QueueItem(['job' => $job, 'ttl' => $ttl]);
        $item
            ->generateId()
            ->touchTime();

        $this->_redis->sadd('queues', $queue);

        $length = $this->_redis->rpush('queue:' . $queue, (string)$item);

        if ($length < 1) {
            return false;
        }

        Module::trace("Pushed " . get_class($job) . " with id " . $item->id . " to queue " . $queue);

        if ($trackStatus) {
            $this->setJobStatus($item->id, JobStatus::STATUS_WAITING);
        }

        return $item->id;
    }

    /**
     * Read job from queue
     *
     * @param string $queue
     * @return QueueItem|null
     */
    public function pop($queue = '')
    {
        if (empty($queue)) {
            $queue = $this->defaultQueue;
        }

        $data = $this->_redis->lpop('queue:' . $queue);
        if (!is_null($data)) {
            $item = new QueueItem();
            $item->loadFromString($data);
            $item->queue = $queue;
            return $item;
        }

        return null;
    }

    public function setJobStatus($id, $status = JobStatus::STATUS_WAITING)
    {
        $statusModel = $this->getJobStatus($id);
        if (is_null($statusModel)) {
            $statusModel = new JobStatus();
        }

        $statusModel->status = $status;

        if (JobStatus::STATUS_WORKING === $status) {
            $statusModel->startedAt = time();
        }

        $statusModel->updatedAt = time();

        $this->_redis->set(JobStatus::getKey($id), (string)$statusModel);
    }

    /**
     * @param $id
     * @return JobStatus|null
     */
    public function getJobStatus($id)
    {
        $data = $this->_redis->get(JobStatus::getKey($id));
        if (!is_null($data)) {
            $status = new JobStatus();
            $status->loadFromString($data);

            return $status;
        }

        return NULL;
    }

    public function deleteJobStatus($id)
    {
        $this->_redis->del(JobStatus::getKey($id));
    }

    public function flushJobStatuses()
    {
        $keys = $this->_redis->keys(JobStatus::getKey('*'));
        foreach ($keys as $key) {
            $this->_redis->del($key);
        }
    }

    public function getQueues()
    {
        return $this->_redis->smembers('queues');
    }

    public function getQueueLength($queue)
    {
        return (int)$this->_redis->llen('queue:' . $queue);
    }

    public function clearQueue($queue)
    {
        $this->_redis->del('queue:' . $queue);
        $this->_redis->srem('queues', $queue);
    }


    /**
     * Check if job is running or queued
     *
     * @param $token
     * @return bool
     */
    public function isActualJob($token)
    {
        return in_array($this->getJobStatus($token), ['waiting', 'running']);
    }
}

