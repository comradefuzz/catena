<?php

namespace ladno\catena\models;
use ladno\catena\exceptions\DeserializationErrorException;
use ladno\catena\Module;
use yii\base\Model;
use yii\redis\ActiveRecord;

/**
 *
 */
class JobStatus extends Model
{
    const STATUS_WAITING = 'waiting';
    const STATUS_WORKING = 'working';
    const STATUS_ERROR = 'error';
    const STATUS_DONE = 'done';

    public $status;
    public $startedAt;
    public $updatedAt;

    public function rules()
    {
        return [
            [['status'], 'string'],
            ['status', 'in', 'range' => [self::STATUS_WAITING, self::STATUS_WORKING, self::STATUS_ERROR, self::STATUS_DONE]],
            [['startedAt', 'updatedAt'], 'integer'],
        ];
    }


    public function __toString()
    {
        return json_encode($this->attributes);
    }

    /**
     * Update time
     */
    public function touch()
    {
        return $this->updatedAt = time();
    }

    public static function getKey($id)
    {
        return 'job:' . $id . ':status';
    }

    /**
     * @param $data
     * @throws DeserializationErrorException
     */
    public function loadFromString($data)
    {
        $data = json_decode($data, true);
        if (is_null($data)) {
            throw new DeserializationErrorException("Unknown job status data format");
        }

        $this->setAttributes($data);
    }
}