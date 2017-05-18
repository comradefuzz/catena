<?php

namespace ladno\catena;

use ladno\catena\components\Monitor;
use ladno\catena\components\Queue;
use ladno\catena\components\Redis;
use ladno\catena\components\Regulars;
use ladno\catena\components\Resque;
use ladno\catena\components\Stat;
use ladno\catena\components\Worker;
use yii\base\BootstrapInterface;
use yii\base\UnknownMethodException;
use yii\console\Application as ConsoleApplication;
use yii\di\Instance;
use yii\log\Logger;
use yii\redis\Connection;
use yii\web\Application as WebApplication;

/**
 * Catena module definition class
 *
 * @property  Connection redis
 * @property Queue queue component
 * @property Stat stat component
 * @property Worker worker component
 * @property Monitor monitor component
 * @property Regulars regulars
 * @method push($job, $queue = '', $trackStatus = false)
 */
class Module extends \yii\base\Module implements BootstrapInterface
{
    const DEFAULT_REGULARS_INTERVAL = 5;

    /**
     * @inheritdoc
     */
    public $controllerNamespace = 'ladno\catena\controllers';


    public $redis;

    protected $_queue = 'queue';

    protected $_stat;
    protected $_worker;
    protected $_monitor;
    protected $_regulars;

    public $logCategory = 'catena';
    public $yiiBinary = '@app/yii';

    /**
     * @var bool Enables tracing in debug mode
     */
    public $verbose = false;

    /**
     * @var bool
     */
    public $enableStatistics = false;

    /**
     * @var bool Enables monitoring dead workers and automatic respawning
     */
    public $autoRespawn = false;

    /**
     * @var int Seconds worker monitor sleeps before respawn dead workers
     */
    public $respawnInterval = 15;

    /**
     * Workers config
     *
     * @var array
     */
    protected $_workers = [];

    /**
     * @var bool
     */
    public $enableRegulars = false;
    /**
     * @var array
     */
    public $regularJobs = [];
    /**
     * @var int seconds
     */
    public $regularsCheckInterval = 5;

    public function bootstrap($app)
    {
        \Yii::setAlias('catena', __DIR__);

        if ($app instanceof WebApplication) {
            // TODO url manager rules
        } elseif ($app instanceof ConsoleApplication) {
            $app->controllerMap[$this->id] = [
                'class' => 'ladno\catena\console\CatenaController',
                'module' => $this
            ];
        }
    }

    public function init()
    {
        $this->redis = Instance::ensure($this->redis, Redis::className());
    }


    /**
     * Proxy all unknown methods to Queue component
     *
     * @param string $name
     * @param array $params
     * @return mixed
     */
    public function __call($name, $params)
    {
        try {
            return parent::__call($name, $params);
        } catch (UnknownMethodException $e) {
            return call_user_func_array([$this->_queue, $name], $params);
        }
    }

    /**
     * @param string $queue
     */
    public function setQueue($queue)
    {
        $this->_queue = $queue;
    }

    /**
     * @return Queue
     */
    public function getQueue()
    {
        return \Yii::$app->get($this->_queue);
    }

    /**
     * @return Stat
     */
    public function getStat()
    {
        if (is_null($this->_stat)) {
            $this->_stat = new Stat();
        }

        return $this->_stat;
    }

    /**
     * @return Worker
     */
    public function getWorker()
    {
        if (is_null($this->_worker)) {
            $this->_worker = new Worker();
        }

        return $this->_worker;
    }

    /**
     * @return Monitor
     */
    public function getMonitor()
    {
        if (is_null($this->_monitor)) {
            $this->_monitor = new Monitor();
        }

        return $this->_monitor;
    }

    /**
     * @return Regulars
     */
    public function getRegulars()
    {
        if (is_null($this->_regulars)) {
            $this->_regulars = new Regulars();
        }

        return $this->_regulars;
    }


    public static function log($message, $level = Logger::LEVEL_INFO, $category = '')
    {
        \Yii::getLogger()->log($message, $level, self::getLogCategory($category));
    }

    public static function trace($message, $category = '')
    {
        \Yii::trace($message, self::getLogCategory($category));
    }

    public static function getLogCategory($category)
    {
        return Module::getInstance()->logCategory . ($category ? '/' . $category : '');
    }

    /**
     * @return array
     */
    public function getWorkers()
    {
        return $this->_workers;
    }

    /**
     * @param array $workers
     */
    public function setWorkers($workers)
    {
        array_walk($workers, function (&$item) {
           $item = array_merge([
               'sleep' => 0,
               'memoryLimit' => 0,
               'count' => 1
           ], $item);
        });

        $this->_workers = $workers;
    }

}
