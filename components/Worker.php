<?php
/**
 * Created by PhpStorm.
 * User: fuzz
 * Date: 17.05.16
 * Time: 16:40
 */

namespace ladno\catena\components;


use ladno\catena\Module;
use Prophecy\Argument\Token\ExactValueToken;
use yii\base\Component;
use yii\base\Exception;
use yii\redis\Connection;

class Worker extends Component
{

    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_STOPPED = 'stopped';
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

    public function register($queues, $group = null)
    {
        $id = $this->getProcessUniqueId();
        $this->_redis->sadd('workers', $id . (!is_null($group) ? ':' . $group : ''));
        $this->update($id, [
            'status' => self::STATUS_ACTIVE,
            'startedAt' => time(),
            'queues' => $queues,
            'group' => $group,
        ]);

        return $id;
    }

    public function unregister($id = null)
    {
        if (empty($id)) {
            $id = $this->getProcessUniqueId();
        }
        $info = $this->getWorkerInfo($id);
        $this->_redis->srem('workers', $id . (!is_null($info['group']) ? ':' . $info['group'] : ''));
        $this->_redis->del('worker:' . $id);
        $this->_module->stat->clearWorkerStat($id);
    }

    public function update($id, $data)
    {
        $storedData = $this->getWorkerInfo($id);
        if (!empty($storedData)) {
            $data = array_merge($storedData, $data);
        }

        $data['updatedAt'] = time();

        $this->_redis->set('worker:' . $id, json_encode($data));
    }

    /**
     * @param bool $local
     * @return array
     */
    public function getWorkers($local = true)
    {
        $workers = $this->_redis->smembers('workers');
        if (true === $local) {
            array_filter($workers, function ($worker) {
                return strpos($worker, gethostname() . ':') === 0;
            });
        }

        $result = [];
        foreach ($workers as $id) {
            $idParsed = explode(':', $id);
            $group = is_null($idParsed[2]) ? 'unknown' : $idParsed[2];
            $result[$group][] = $idParsed[0] . ':' . $idParsed[1];
        }

        ksort($result);

        return $result;
    }

    public function getWorkerInfo($id)
    {
        $data = $this->_redis->get('worker:' . $id);
        if (!empty($data)) {
            return json_decode($data, true);
        }

        return null;
    }

    /**
     * @param $id
     * @return boolean
     */
    public function check($id)
    {
        $pid = $this->id2pid($id);
        return posix_kill($pid, SIG_BLOCK);
    }

    public function stop($id)
    {
        $pid = $this->id2pid($id);
        posix_kill($pid, SIGTERM);
        $this->update($id, [
            'status' => self::STATUS_STOPPED
        ]);
    }

    public function pause($id)
    {
        $pid = $this->id2pid($id);
        posix_kill($pid, SIGTSTP);

        $this->update($id, [
            'status' => self::STATUS_PAUSED
        ]);
    }

    public function resume($id)
    {
        $pid = $this->id2pid($id);
        posix_kill($pid, SIGCONT);
        $this->update($id, [
            'status' => self::STATUS_ACTIVE
        ]);
    }

    public function kill($id)
    {
        $pid = $this->id2pid($id);
        posix_kill($pid, SIGKILL);

        $this->unregister($id);
    }


    /**
     * @param $id
     * @return int
     * @throws Exception
     */
    public function id2pid($id)
    {
        $idParsed = explode(':', $id);
        if (empty($idParsed[1])) {
            throw new Exception("Invalid worker id: {$id}");
        }
        return (int)$idParsed[1];
    }

    public function clearDeadWorkers()
    {
        $aliveIds = [];
        $workers = $this->getWorkers();
        foreach ($workers as $group => $items) {
            foreach ($items as $id) {
                if (!$this->check($id)) {
                    $this->unregister($id);
                } else {
                    $aliveIds[] = $id;
                }
            }
        }

        // Clear orphaned worker info keys
        $workerKeys = $this->_redis->keys("worker:" . gethostname() . ":*");
        foreach ($workerKeys as $key) {
            $id = preg_replace("/^worker:/", "", $key);
            if (in_array($id, $aliveIds)) {
                continue;
            }
            $this->_module->stat->clearWorkerStat($id);
            $this->_redis->del($key);
        }
    }

    protected function getProcessUniqueId()
    {
        return gethostname() . ':' . getmypid();
    }
}

