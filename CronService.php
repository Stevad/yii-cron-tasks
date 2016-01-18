<?php

/**
 * Class CronService
 *
 * Application component to run user callback with definitions of the tasks and gather detailed information about
 * their execution status.
 *
 * User callback should be valid PHP callback (take a look on definition of call_user_func() function) to the
 * function that returns configured CronTask instances.
 * Also for the first argument you can define array with class definition to create instance with Yii::createComponent()
 * method.
 *
 * Example of callback and class content with several tasks definitions.
 * In protected/config/console.php:
 * ...
 * 'components' => array(
 *     'cron' => array(
 *         'tasksCallback' => array(
 *             array('class' => 'application.models.AppCronTasks'),
 *             'getTasks'
 *         )
 *     )
 * )
 * ...
 *
 * In protected/models/AppCronTasks.php:
 * <?php
 * class AppCronTasks
 * {
 *     public function getTasks()
 *     {
 *         $tasks = array();
 *         // call console command 'mail' with action 'sendInvites' every hour each 2 minutes starting from 9th
 *         // and save output to protected/runtime/console-mail-invites.txt
 *         $task1 = new CronTask('mail', 'sendInvites');
 *         $tasks[] = $task1
 *             ->name('Send invites via mail')
 *             ->minute('9/2')
 *             ->setOutputFile(Yii::app()->getRuntimePath() . '/console-mail-invites.txt');
 *         // call console command 'import' with action 'products' every day at 00:00 and save output
 *         $task2 = new CronTask('import', 'products');
 *         $tasks[] = $task2
 *             ->name('Import products (daily)')
 *             ->daily()
 *             ->setOutputFile(Yii::app()->getRuntimePath() . '/product-import.txt');
 *
 *         return $tasks;
 *     }
 * }
 * ?>
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 * @date 18.01.2016
 */
class CronService extends CApplicationComponent
{
    /**
     * @var array valid PHP callback definition. First argument can be array with class definition to create instance
     * by Yii::createComponent()
     */
    public $tasksCallback;

    /**
     * @var string alias path to directory to store CronProcess logs
     */
    public $runtimePathAlias;

    /**
     * @var CronTask[] detailed information about specified cron tasks
     */
    private $activeTasks = array();

    /**
     * @var string working runtime path
     */
    private $runtimePath;

    /**
     * Log message with predefined category. It allows to separate logs on application level. Example of config:
     * 'components' => array(
     *     ...
     *     'log' => array(
     *         ...
     *         'routes' => array(
     *             ...
     *             array(
     *                 'class' => 'CFileLogRoute',
     *                 'levels' => 'error, warning, info',
     *                 'categories' => 'cron-tasks',
     *                 'logFile' => 'cron.log'
     *             )
     *         )
     *     )
     *     ...
     * )
     *
     * @param string $message log message
     * @param string $level level of the message (see CLogger)
     */
    public static function log($message, $level = CLogger::LEVEL_INFO)
    {
        Yii::log($message, $level, 'cron-tasks');
    }

    /**
     * Initialize component. Import extension classes. Check attributes.
     * @throws InvalidArgumentException
     */
    public function init()
    {
        parent::init();

        if (empty($this->tasksCallback)) {
            throw new InvalidArgumentException(
                'You should specify callback with tasks definitions.'
            );
        }

        if (!is_array($this->tasksCallback) || count($this->tasksCallback) !== 2) {
            throw new InvalidArgumentException(
                'Callback must be a array with valid PHP callback description.'
            );
        }

        if ($this->runtimePathAlias === null) {
            $this->runtimePath = Yii::app()->getRuntimePath() . DIRECTORY_SEPARATOR . 'cron';
        } else {
            $this->runtimePath = Yii::getPathOfAlias($this->runtimePathAlias);
        }

        if (!file_exists($this->runtimePath)) {
            mkdir($this->runtimePath);
        }
    }

    /**
     * Runs specified callback to get available cron tasks and store them in this component.
     */
    public function loadTasks()
    {
        $this->activeTasks = array();
        $tasks = $this->runCallback();

        if (!is_array($tasks)) {
            throw new RuntimeException('Callback must return array of CronTask instances');
        }

        /** @var CronTask $task */
        foreach ($tasks as $task) {
            if (!is_object($task) || !($task instanceof CronTask)) {
                throw new RuntimeException('One of the callback results is not a CronTask instance');
            }

            $this->activeTasks[$task->getId()] = $task;
        }
    }

    /**
     * Get previously loaded task instances.
     * @return CronTask[]
     */
    public function getActiveTasks()
    {
        return $this->activeTasks;
    }

    /**
     * Get working directory for info files
     * @return string
     */
    public function getRuntimePath()
    {
        return $this->runtimePath;
    }

    /**
     * Check specified callback and run it returning result of execution.
     * @return CronTask[]
     * @throws CException
     */
    private function runCallback()
    {
        $callback = $this->tasksCallback;
        if (is_array($callback[0]) && array_key_exists('class', $callback[0])) {
            $callback[0] = Yii::createComponent($callback[0]);
        }

        return call_user_func($callback);
    }
}