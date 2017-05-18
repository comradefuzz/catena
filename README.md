Catena
======
Redis based queued background jobs system inspired by `resque`

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist ladno/catena "*"
```

or add

```
"ladno/catena": "*"
```

to the require section of your `composer.json` file.

Attention
---------
Module is in development mode and should not be used in production

Usage
-----
Configure your application like following:

```
 'bootstrap' => ['catena'],
 'components' => [
    'queue' => 'ladno\catena\components\Queue',
 ],
 'modules' => [
        'catena' => [
            'class' => 'ladno\catena\Module',
            'autoRespawn' => false, // If set worker groups will be kept in actual state by respawning dead workers
                                    // (defaults `false`)
            'enableRegulars' => false,  // If this is set and `regularJobs` is not empty, jobs will be automatically set
                                        // to queues with specified interval (defaults `false`)
            'redis' => [
                // Redis connection
                'connection' => [
                    'class' => '\yii\redis\Connection',
                    'hostname' => 'redis',
                ],
                'namespace' => 'catena', // prefix all module data has (defaults to `catena`)
            ],
            // Worker groups configuration
            'workers' => [
                [
                    'queues' => 'harder', // define queues workers are listening to
                    'count' => 3, // specify workers count in group (more workers means faster processing)
                    'sleep' => 15, // number of seconds each worker sleeps if all jobs are done
                    'memoryLimit' => 3 * 1024 * 1024 // bytes of RAM allowed to use to each worker in group
                ],
                [
                    'queues' => 'better,stronger', // you can define several queues to worker group
                    'count' => 2
                ],
                ['queues' => 'faster'],
            ],
            'regularJobs' => [
                [
                    '\app\jobs\RegularJob', // Job class
                    'queue' => 'harder',    // Queue to place job
                    'args' => ['foo' => 'bar'], // Job arguments
                    'interval' => 15    // Interval in seconds in which job will be enqueued
                ],
                [
                    '\app\jobs\RegularJob',
                    'queue' => 'harder',
                    'args' => ['foo' => 'bazzz'],
                    'interval' => 15,
                    'descriptor' => 'another', // Allows to have several regular jobs of one class with different arguments
                ],
            ]
        ]
    ],
 ```
 
 Define job class

 ```
 <?php
 namespace app\jobs;

 use ladno\catena\models\BaseJob;
 
 class MyJob extends BaseJob
 {
     public $foo;
     public $bar;
 
     public function rules()
     {
        return [
            [['foo', 'bar'], 'string'],
            ['foo', 'required'],
        ];
     }
     public function perform()
     {
         \Yii::info("Job is done!");
     }
 }
```

Now you can run this job in background:

```
Yii::$app->queue->push(new \app\jobs\MyJob(['foo' => 'bar']), 'heavy');
```

Then start catena daemons `yii catena/start`. 
See module status using `yii catena/status`. 
Stop daemons with `yii catena/stop`.