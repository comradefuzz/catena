<?php

namespace ladno\catena\console;

use ladno\catena\components\Resque;
use ladno\catena\models\QueueItem;
use ladno\catena\Module;
use yii\helpers\Console;
use yii\log\Logger;

/**
 * Catena module controller
 * @property Module module
 */
class CatenaController extends \yii\console\Controller
{
    const REGULARS_PIDFILE = '@queue/tmp/regulars.pid';
    const REGULARS_LOGFILE = '@queue/logs/regulars.log';
    const REGULARS_BINFILE = '@vendor/bin/regulars';

    /**
     * @var string Enable statistics for working in queue/status
     */
    public $workers;
    /**
     * @var string Enable statistics for regular jobs in queue/status
     */
    public $regulars;


    public function init()
    {
        parent::init();

    }

    public function getUniqueId()
    {
        // Make this controller default for module
        return $this->module->getUniqueId();
    }

    public function options($actionId)
    {
        $options = parent::options($actionId);
        if (in_array($actionId, ['status', 'start'])) {
            $options[] = 'workers';
            $options[] = 'regulars';
        }

        return $options;
    }


    public function actions()
    {
        return [
            'regulars' => 'ladno\catena\console\actions\RegularsAction',
            'listen' => 'ladno\catena\console\actions\ListenAction',
            'monitor' => 'ladno\catena\console\actions\MonitorAction',
        ];
    }


    /**
     * Renders the index view for the module
     * @return string
     */
    public function actionIndex()
    {
        $this->run('/help', [$this->id]);
    }

    /**
     * Start catena daemons
     */
    public function actionStart()
    {
        if ($this->module->autoRespawn) {
            $this->run("start-monitor");
            $requiredCount = 0;
            foreach ($this->module->workers as $item) {
                $requiredCount += !empty($item['count'])
                    ? (int)$item['count']
                    : 1;
            }

            $this->waitWorkers($requiredCount);
        } else {
            $this->run("start-workers");
        }

        if ($this->module->enableRegulars && !empty($this->module->regulars->getJobs())) {
            $this->run("start-regulars");
        }

        $this->run("status");
    }

    /**
     * Start worker group(s)
     *
     * @param null $group
     */
    public function actionStartWorkers($group = null)
    {
        $runningWorkers = $this->module->worker->getWorkers();
        foreach ($this->module->workers as $groupId => $worker) {
            if (!is_null($group) && $groupId != $group) {
                continue;
            }

            $count = (int)$worker['count'];

            $delta = !empty($runningWorkers[$groupId])
                ? $count - count($runningWorkers[$groupId])
                : $count;

            for ($i = 1; $i <= $delta; $i++) {
                $this->runBackgroundCommand("listen {$worker['queues']} {$groupId} {$worker['sleep']} {$worker['memoryLimit']}");
            }
        }

        if (!is_null($group)) {
            $requiredCount = $this->module->workers[$group]['count'];
        } else {
            $requiredCount = 0;
            foreach ($this->module->workers as $item) {
                $requiredCount += (int)$item['count'];
            }
        }

        $this->waitWorkers($requiredCount, $group);
    }

    protected function waitWorkers($requiredCount, $group = null, $waitSeconds = 5)
    {
        for ($i = 1; $i <= $waitSeconds; $i++) {
            $count = 0;
            foreach ($this->module->worker->getWorkers() as $groupId => $workers) {
                if (!is_null($group) && ($groupId !== $group)) {
                    continue;
                }

                foreach ($workers as $id) {
                    $count++;
                }
            }

            if ($count == $requiredCount) {
                break;
            }
            sleep(1);
        }
    }

    public function actionStartMonitor()
    {
        $this->runBackgroundCommand("monitor");
    }

    public function actionStartRegulars()
    {
        if (!$this->module->enableRegulars) {
            $this->output("%rRegular jobs are disabled in module configuration");
            return;
        }
        $this->runBackgroundCommand("regulars");
    }


    /**
     * Stop catena daemons
     */
    public function actionStop()
    {
        if ($this->module->monitor->isActive()) {
            $this->run("stop-monitor");
        }

        $this->run("status-queues");

        if ($this->module->enableRegulars && !empty($this->module->regulars->getJobs())) {
            $this->run("stop-regulars");
        }

        $this->run("stop-workers");
        $this->waitWorkers(0);
        $this->run("status-workers");
        $this->run("status-regulars");
    }

    /**
     * Stop worker group(s)
     * @param null $group
     */
    public function actionStopWorkers($group = null)
    {
        $this->sendWorkerSignal($group, 'stop');
        $this->output("%yStop signal is sent to " . (
            (!is_null($group))
                ? "workers group $group"
                : "all workers")
        );

        $this->waitWorkers(0);
    }

    /**
     * @param int $wait seconds to wait stopping
     */
    public function actionStopMonitor($wait = 10)
    {
        $this->module->monitor->stop();
        for ($i = 1; $i <= $wait; $i++) {
            if (!$this->module->monitor->isActive()) {
                $this->output("%yMonitor is stopped");
                return;
            }
            sleep(1);
        }

        $this->output("%yMonitor stop signal sent, but it didn't shutdown in $wait seconds");
    }

    public function actionStopRegulars()
    {
        $this->output("%yRegular jobs scheduler is shutting down");
        $this->module->regulars->stop();
    }


    /**
     * Kill worker group(s)
     * @param null $group
     */
    public function actionKillWorkers($group = null)
    {
        $this->output("%yKill signal is sent to workers");
        $this->sendWorkerSignal($group, 'kill');
    }

    /**
     * Pause worker group(s)
     * @param null $group
     */
    public function actionPause($group = null)
    {
        $this->sendWorkerSignal($group, 'pause');
    }

    /**
     * Resume worker group(s)
     * @param null $group
     */
    public function actionResume($group = null)
    {
        $this->sendWorkerSignal($group, 'resume');
    }

    protected function sendWorkerSignal($group, $signal)
    {
        foreach ($this->module->worker->getWorkers() as $groupId => $items) {
            if (!is_null($group) && $groupId != $group) {
                continue;
            }

            foreach ($items as $id) {
                $this->module->worker->{$signal}($id);
            }
        }
    }

    public function runBackgroundCommand($command)
    {
        $yiiBinary = \Yii::getAlias($this->module->yiiBinary);
        exec("nohup $yiiBinary {$this->id}/$command > /dev/null 2>&1 &");
    }

    /**
     * Show queues and workers status
     */
    public function actionStatus()
    {
        $this->run("status-queues");
        $this->run("status-workers");

        if ($this->module->enableRegulars && !empty($this->module->regulars->getJobs())) {
            $this->run("status-regulars");
        }
    }

    public function actionStatusQueues()
    {
        $this->output("------------------------");
        if ($this->module->monitor->isActive()) {
            $this->output("Monitor is %grunning%n [" . $this->module->monitor->getPid() . "]");
        } else {
            $this->output("Monitor is %rnot running");
        }
        $this->output("------------------------");

        $queues = $this->module->queue->getQueues();
        sort($queues);
        $this->output("Active queues:" . count($queues));
        foreach ($queues as $queue) {
            $length = $this->module->queue->getQueueLength($queue);
            $processed = $this->module->stat->getQueueStat($queue, 'processed');
            $failed = $this->module->stat->getQueueStat($queue, 'failed');

            $this->output("  * %y{$queue}%n - {$length} (%g{$processed}%n/%r{$failed}%n)");
        }
    }


    public function actionStatusWorkers()
    {
        $this->output("------------------------");
        $this->output("Workers:");

        $activeGroups = $this->module->worker->getWorkers();
        if (empty($activeGroups)) {
            $this->output("No active worker groups");
        } else {
            foreach ($activeGroups as $group => $items) {
                $result = '';
                foreach ($items as $id) {
                    $pid = $this->module->worker->id2pid($id);
                    $info = $this->module->worker->getWorkerInfo($id);
                    $active = $this->module->worker->check($id);
                    $processed = $this->module->stat->getWorkerStat($id, 'processed');
                    $failed = $this->module->stat->getWorkerStat($id, 'failed');
                    $result .=
                        "  * [$pid] "
                        . "%y{$info['status']}%n since " . date('Y-m-d H:i:s', $info['updatedAt'])
                        . (!$active ? " %r[Dead]%n" : "")
                        . " (%g{$processed}%n/%r{$failed}%n)"
                        . PHP_EOL;

                    $queues = is_array($info['queues']) ? $info['queues'] : [];
                }
                $this->output("Group %y{$group}%n (" . implode(',', $queues) . ")" . PHP_EOL . $result);
            }
        }
    }

    public function actionStatusRegulars()
    {
        $this->output("------------------------");
        if ($this->module->regulars->isActive()) {
            $this->output("Scheduler is %grunning%n [" . $this->module->regulars->getPid() . "]");
        } else {
            $this->output("Scheduler is %rnot running");
        }
        $this->output("------------------------");
        $this->output("Regular jobs:");
        foreach ($this->module->regulars->getJobs() as $job) {
            $info = $this->module->regulars->getJobData($job['descriptor']);
            $this->output("  * [{$job['descriptor']}] in %y{$job['queue']}%n every {$job['interval']} seconds"
                . " (" . date("Y-m-d H:i:s", $info['updatedAt']) . ")"
            );
        }
    }

    /**
     * Clears catena data
     */
    public function actionClear()
    {
        if (!$this->interactive  || Console::confirm("Are you sure?")) {
            $this->output("Clearing all module data");
            $this->run("clear-queues");
            $this->run("clear-tracking");
            $this->run("clear-workers");
            $this->run("clear-stats");
        }
    }

    /**
     * Purge queues data
     * @param string $queue
     */
    public function actionClearQueues($queue = '')
    {
        $queues = empty($queue) ? $this->module->queue->getQueues() : [$queue];

        foreach ($queues as $queueName) {
            $this->module->queue->clearQueue($queueName);
            $this->module->stat->clearQueueStat($queueName);
        }
    }

    /**
     * Remove all tracked jobs statuses
     */
    public function actionClearTracking()
    {
        $this->module->queue->flushJobStatuses();
    }

    /**
     * Clear dead workers data
     */
    public function actionClearWorkers()
    {
        $this->module->worker->clearDeadWorkers();
    }

    /**
     * Clear stats data
     */
    public function actionClearStats()
    {
        $this->run("clear-stats-queue");
        $this->run("clear-stats-workers");
    }

    public function actionClearStatsQueue($queue = null)
    {
        foreach ($this->module->queue->getQueues() as $queueId) {
            if (!is_null($queue) && ($queue !== $queueId)) {
                continue;
            }
            $this->module->stat->clearQueueStat($queueId);
        }
    }

    public function actionClearStatsWorkers()
    {
        $this->module->stat->clearWorkersStat();
    }

    /**
     * Show single tracked job status
     *
     * @param $id
     */
    public function actionJobStatus($id)
    {
        $status = $this->module->queue->getJobStatus($id);

        Console::output(
            "Status: {$status->status},"
            . " started at: " . \Yii::$app->formatter->asTime($status->startedAt)
            . " updated at: " . \Yii::$app->formatter->asTime($status->updatedAt)
        );
    }

    /**
    * Counts active workers in queue
    *
    * @param $queue 
    */
    public function actionWorkersCount($queue = 'default') {
        $activeGroups = $this->module->worker->getWorkers();
        if (!empty($activeGroups)) {
            $workers = 0;
            foreach ($activeGroups as $group => $items) {
                foreach ($items as $id) {
                    $info = $this->module->worker->getWorkerInfo($id);
                    $queues = is_array($info['queues']) ? $info['queues'] : [];
                    if(in_array($queue, $queues)) {
                        $workers++;
                    }
                }
            }
            echo $workers;
        }
        else {
            echo 0;
        }
    }

    protected function output($string)
    {
        Console::output(Console::renderColoredString($string . "%n"));
    }


    public function actionTest($count = 10, $ttl = 0, $track = false)
    {
        $queues = ['harder', 'better', 'stronger', 'faster'];

        $buffer = [];
        for ($i = 1; $i <= $count; $i++) {
            if (empty($buffer)) {
                $buffer = $queues;
            }

            $fail = false;
            if (0 === $i % 5) {
                $fail = true;
            }

            $queue = array_shift($buffer);
            $id = $this->module->queue->push(new \ladno\catena\jobs\TestJob(['queue' => $queue, 'fail' => $fail]), $queue, $track, $ttl);
            if ($fail) {
                $this->output($i . ' ' . $id);
            }
        }

        $this->run('status');
    }
}
