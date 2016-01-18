<?php

/**
 * Class CronProcess
 *
 * Represents process for CronTask with additional information about current running status, last times of start/stop
 * and PID of running instance.
 *
 * CronProcess is created for each CronTask. Execution process is the following:
 * 1. Create process from CronTask instance
 * 2. If the task can be started - save info file with it's definition and start background wrapper process with special
 *    console action 'cron/run' with task identifier.
 * 3. In console action get task identifier and run process: register shutdown function (to handle unexpected
 *    termination of the task) and redirect runtime process to specified console command.
 * 4. Log and save to info file information about task termination - successful or not (error, exception).
 * 5. Profit :-)
 *
 * Information about each task serialized and stored in special file named by task unique id with '.json' extension.
 * Files stored in runtime path specified by cron application component.
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 * @date 18.01.2016
 */
class CronProcess
{
    const STATUS_NEW = 0;
    const STATUS_RUNNING = 1;
    const STATUS_FINISHED = 2;
    const STATUS_FAILED = 3;

    /**
     * @var int status of the task execution at the moment
     */
    public $status = self::STATUS_NEW;

    /**
     * @var string last date and time of task start
     */
    public $lastStart;

    /**
     * @var string last date and time of task stop
     */
    public $lastStop;

    /**
     * @var int PID of running task instance
     */
    public $pid;

    /**
     * @var string unique hash from CronTask instance
     */
    private $id;

    /**
     * @var string name from CronTask instance
     */
    private $name;

    /**
     * @var string console command from CronTask instance
     */
    private $command;

    /**
     * @var string console command action from CronTask instance
     */
    private $action;

    /**
     * @var array list of params from CronTask instance
     */
    private $params;

    /**
     * @var bool uniqueness flag from CronTask instance
     */
    private $unique;

    /**
     * @var string shell command to run task wrapper
     */
    private $_wrapperCommand;

    /**
     * @var CronService application component instance
     */
    private $_service;

    /**
     * Static method to create new instance and get information about last execution. Used in console daemon action.
     * @param CronTask $task configured task instance
     * @param CronService $service application service component
     * @return CronProcess
     */
    public static function createByTask(CronTask $task, CronService $service)
    {
        $process = new self($service, $task->getId());
        $process->readInfoFile();
        $process->unique = $task->isUnique();
        $process->name = $task->getName();
        $process->command = $task->getCommand();
        $process->action = $task->getCommandAction();

        $params = array();
        foreach ($task->getParams() as $param => $value) {
            $params[] = "--{$param}={$value}";
        }
        $process->params = $params;

        $app = Yii::app()->getBasePath() . DIRECTORY_SEPARATOR . 'yiic';
        $output = $task->getOutputFile() ? "> {$task->getOutputFile()}" : '>> /dev/null';
        $process->_wrapperCommand = "{$app} cron run --id={$task->getId()} {$output} 2>&1 & echo $!";

        return $process;
    }

    /**
     * Static method to create process instance by task identifier. Used in special wrapper command to run specified
     * task and log it's execution.
     * @param string $id
     * @param CronService $service application service component
     * @return self
     */
    public static function createById($id, CronService $service)
    {
        $process = new self($service, $id);
        $process->readInfoFile(true);

        return $process;
    }

    /**
     * Get if task process is running at the moment
     * @return bool
     */
    public function isRunning()
    {
        return ($this->status === self::STATUS_RUNNING);
    }

    /**
     * Save info file and task wrapper
     */
    public function runWrapper()
    {
        $this->saveInfoFile();
        exec($this->_wrapperCommand);
    }

    /**
     * Run console command saved in the process. Handle normal and abnormal termination (save status to lock file and
     * log message)
     */
    public function run()
    {
        $this->checkIsCLI();

        if ($this->unique && $this->isRunning()) {
            CronService::log(
                "Cannot run task '{$this->name}': it is still running and does not allow overlapping (unique)",
                CLogger::LEVEL_WARNING
            );
            return;
        }

        $this->pid = getmypid();
        $this->status = self::STATUS_RUNNING;
        $this->lastStart = date('Y-m-d H:i:s');
        $this->saveInfoFile();

        CronService::log("Task '{$this->name}' started (PID: {$this->pid})");

        // to log task failure if error or exception occurred
        register_shutdown_function(array($this, 'shutdown'));

        /** @var CConsoleCommand $command */
        $command = Yii::app()->getCommandRunner()->createCommand($this->command);
        $command->init();

        $params = $this->params;
        $action = $this->action ?: $command->defaultAction;
        array_unshift($params, $action);
        $command->run($params);

        // normal end of the task process
        $this->status = self::STATUS_FINISHED;
        CronService::log("Task '{$this->name}' successfully finished");
    }

    /**
     * Called by PHP on shutdown process. Checks if task was successfully finished.
     * Allowed only in CLI mode.
     * @throws CException
     */
    public function shutdown()
    {
        $this->checkIsCLI();

        $this->pid = null;
        $this->lastStop = date('Y-m-d H:i:s');

        // not finished in usual way (exception or another error)
        if ($this->status === self::STATUS_RUNNING) {
            $this->status = self::STATUS_FAILED;
        }

        $this->saveInfoFile();

        if ($this->status === self::STATUS_FAILED) {
            CronService::log(
                "Task '{$this->name}' unexpectedly finished. Check logs and console command",
                CLogger::LEVEL_ERROR
            );

            // force flush application logs
            Yii::getLogger()->flush(true);
        }
    }

    /**
     * Private constructor to prevent manual instantiating outside of the special static methods
     * @param CronService $service
     * @param string $id
     */
    private function __construct(CronService $service, $id)
    {
        $this->_service = $service;
        $this->id = $id;
    }

    /**
     * Load file with information about process (JSON content). Decode data and set attributes of current instance.
     * Identifier attribute should be set before calling this method.
     * @param bool|false $exceptionNoFile
     * @throws RuntimeException
     */
    private function readInfoFile($exceptionNoFile = false)
    {
        $file = $this->getInfoFileName();

        if (file_exists($file) && is_readable($file)) {
            $data = json_decode(file_get_contents($file), true);

            if (!empty($data)) {
                foreach ($data as $key => $value) {
                    $this->$key = $value;
                }

                $this->checkProcessAvailability();
            }
        } else {
            if ($exceptionNoFile) {
                throw new RuntimeException('Process info file is not available. Wrong hash?');
            }
        }
    }

    /**
     * Check if task process really active and running
     */
    private function checkProcessAvailability()
    {
        if ($this->pid !== null && $this->status === self::STATUS_RUNNING) {
            exec("ps -p {$this->pid} -o pid", $output);
            if (count($output) != 2) {
                $this->pid = null;
                $this->status = self::STATUS_FAILED;
                CronService::log(
                    "Task '{$this->name}' unexpectedly finished. Check logs and console command",
                    CLogger::LEVEL_ERROR
                );
            }
        }
    }

    /**
     * Serialize current instance attributes to the file.
     * Allowed only in CLI mode.
     */
    private function saveInfoFile()
    {
        $this->checkIsCLI();
        $data = get_object_vars($this);
        unset($data['_service'], $data['_wrapperCommand']);

        file_put_contents($this->getInfoFileName(), json_encode($data), LOCK_EX);
    }

    /**
     * Generate name of the file with process information
     * @return string
     */
    private function getInfoFileName()
    {
        return $this->_service->getRuntimePath() . DIRECTORY_SEPARATOR . $this->id . '.json';
    }

    /**
     * Check current PHP_SAPI constant value. If not 'cli' (console mode) - throw runtime exception.
     * @throws RuntimeException
     */
    private function checkIsCLI()
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('You cannot run cron process in non CLI mode');
        }
    }
}