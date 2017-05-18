<?php

namespace ladno\catena\models;
use ladno\catena\exceptions\DeserializationErrorException;
use yii\base\Model;

/**
 *
 * @property string id
 * @property BaseJob job
 */
class QueueItem extends Model
{
    /**
     * @var string
     */
    public $id;

    /**
     * @var string
     */
    public $queue;

    /**
     * Enqueue time
     * @var integer
     */
    public $createdAt;

    /**
     * TTL of job in seconds
     * @var integer
     */
    public $ttl;

    /**
     * @var BaseJob
     */
    protected $_job;

    public function rules()
    {
        return [
            ['id', 'string'],
            [['createdAt', 'ttl'], 'integer'],
        ];
    }


    public function __toString()
    {
        return json_encode([
            'id' => $this->id,
            'class' => get_class($this->job),
            'attributes' => $this->job->attributes,
            'createdAt' => $this->createdAt,
            'ttl' => $this->ttl,
        ]);
    }

    /**
     * Generates job unique id
     * @return $this
     */
    public function generateId()
    {
        $this->id = md5(uniqid('', true));

        return $this;
    }

    /**
     * Update time
     *
     * @return $this
     */
    public function touchTime()
    {
        $this->createdAt = time();

        return $this;
    }


    /**
     * @param $data
     * @throws DeserializationErrorException
     */
    public function loadFromString($data)
    {
        $data = json_decode($data, true);

        if (is_null($data) || empty($data['class'])) {
            throw new DeserializationErrorException("Invalid queue item format");
        }

        $this->setAttributes($data);

        /**
         * @var BaseJob $job
         */
        $this->job = new $data['class'];

        if (!empty($data['attributes'])) {
            $this->job->setAttributes($data['attributes'], false);
        }
    }

    /**
     * @return BaseJob
     */
    public function getJob()
    {
        return $this->_job;
    }

    /**
     * @param BaseJob $job
     */
    public function setJob(BaseJob $job)
    {
        $this->_job = $job;
    }
}