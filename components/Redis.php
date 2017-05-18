<?php

namespace ladno\catena\components;

use ladno\catena\Module;
use yii\base\Object;
use yii\db\Exception;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\redis\Connection;

/**
 * Redis wrapper to add namespace support and various helper methods.
 */
class Redis extends Object
{
	/**
	 * Redis namespace
	 * @var string
	 */
	public $namespace = 'catena';

    /**
     * @var Connection
     */
    public $connection;

    protected $_prefix;

    public function init()
    {
        $this->connection = Instance::ensure($this->connection, Connection::className());
    }

    /**
     * Proxying methods to redis connection with namespace prefixed keys
     *
     * @param string $name
     * @param array $args
     * @return mixed
     * @throws Exception
     */
	public function __call($name, $args)
	{
		if (in_array(strtoupper($name), $this->connection->redisCommands)) {
			if (is_array($args[0])) {
				foreach ($args[0] AS $i => $v) {
					$args[0][$i] = $this->getPrefix() . $v;
				}
			}
			else {
				$args[0] = $this->getPrefix() . $args[0];
			}
		}

		try {
		    return call_user_func_array([$this->connection, $name], $args);
		}
		catch (Exception $e) {
			throw new Exception('Error communicating with Redis: ' . $e->getMessage(), 0, $e);
		}
	}

    /**
     * Overloaded KEYS method
     *
     * @param $pattern
     * @return mixed
     */
	public function keys($pattern) {

	    $result = $this->__call('keys', [$pattern]);

        if (!empty($result)) {
            array_walk($result, function (&$item) {
                $item = preg_replace('/^' . $this->getPrefix() . '/', '', $item);
            });
        }

        return $result;
    }


	public function getPrefix()
	{
	    if (is_null($this->_prefix)) {

	        $this->_prefix = $this->namespace ? $this->namespace . ':' : '';
        }

	    return $this->_prefix;
	}
}
