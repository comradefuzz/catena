<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 20.05.16
 * Time: 13:51
 */

namespace ladno\catena\models;

use ladno\catena\exceptions\DontPerformException;
use yii\base\Model;

/**
 * Class BaseJob
 *
 * @property string id
 * @package ladno\catena\models
 */
abstract class BaseJob extends Model
{
    public function setUp(){}

    abstract public function perform();

    public function tearDown(){}
}