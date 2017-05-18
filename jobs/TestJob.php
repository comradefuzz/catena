<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 17.10.16
 * Time: 13:08
 */

namespace ladno\catena\jobs;


use ladno\catena\models\BaseJob;
use ladno\catena\Module;
use yii\base\Exception;

class TestJob extends BaseJob
{
    public $foo;
    public $bar;

    public $fail = false;

    public $queue;

    public function rules()
    {
        return [
//            [['foo', 'bar'], 'required'],
            [['foo', 'bar', 'queue'], 'string'],
            [['fail'], 'boolean'],
        ];
    }

    public function perform()
    {
        Module::log(strtoupper($this->queue) . "! Working with {$this->foo} and {$this->bar}");

        if ($this->fail) {
            throw new Exception("FAILED! HAHA!");
        }

//        sleep(1);
    }
}