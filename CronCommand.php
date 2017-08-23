<?php

/**
 * CronCommand represents console command to run actions for daemon, task wrapper and to display tasks statuses.
 *
 * Daemon action need to be manually added to the server's cron schedule.
 * Example of cron entry: * * * * * php /path/to/yiic cron daemon >> /dev/null 2>&1
 *
 * Run action create CronProcess and runs task, serialized in it.
 *
 * Default index action prints in console current list of tasks with detailed information: cron schedule, task route
 * with params, last start and stop time and current status.
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 * @date 23.08.2017
 */
class CronCommand extends CConsoleCommand
{
    /** 
     * @var bool Flag to control if processing of the cron tasks is enabled
     */
    public $enabled = true;
    /** @var CronService */
    private $_service;

    
    /**
     * @inheritdoc
     */
    public function beforeAction($action, $params)
    {
        $this->_service = Yii::app()->cron;

        return parent::beforeAction($action, $params);
    }

    /**
     * Daemon action to run every minute (need to be manually added to the server's cron schedule)
     * and check if any of the specified tasks by user can be executed.
     * For each task get CronProcess instance with information of it's current state. Check run conditions (time and
     * date, uniqueness) and run wrapper command if all conditions are met.
     */
    public function actionDaemon()
    {
        if (!$this->enabled) {
            CronService::log("Cron command processor is disabled", CLogger::LEVEL_WARNING);
            return;
        }
        
        $this->_service->loadTasks();

        /** @var CronTask $task */
        foreach ($this->_service->getActiveTasks() as $task) {
            if (!$task->canRun()) {
                continue;
            }

            if ($task->getProcessInfo()->isRunning() && $task->isUnique()) {
                CronService::log(
                    "Cannot run task '{$task->getName()}': it is still running and does not allow overlapping (is unique)",
                    CLogger::LEVEL_WARNING
                );
            } else {
                $task->getProcessInfo()->runWrapper();
            }
        }
    }

    /**
     * Wrapper to handle execution process of the task
     * @param string $id unique identifier of the task to be executed
     */
    public function actionRun($id)
    {
        if (!$this->enabled) {
            CronService::log("Cron command processor is disabled", CLogger::LEVEL_WARNING);
            return;
        }
        
        CronProcess::createById($id, $this->_service)->run();
    }

    /**
     * Action to display detailed status of current available tasks
     */
    public function actionIndex()
    {
        $this->_service->loadTasks();
        
        echo 'Cron processor status: ' . ($this->enabled ? 'ACTIVE' : 'DISABLED') . "\n\n";

        /** @var CronTask $task */
        foreach ($this->_service->getActiveTasks() as $task) {
            $process = $task->getProcessInfo();
            echo "Task '{$task->getName()}':\n";
            $output = $task->getOutputFile() ? " > {$task->getOutputFile()}" : '';
            $params = array();
            foreach ($task->getParams() as $key => $value) {
                $params[] = "--{$key}={$value}";
            }
            echo "{$task->getCron()} {$task->getCommand()} {$task->getCommandAction()} ";
            echo implode(' ', $params) . "{$output}\n";
            echo "Last start: {$process->lastStart}     Last finish: {$process->lastStop}     ";
            echo 'Status: ';
            switch ($process->status) {
                case CronProcess::STATUS_NEW:
                    echo 'NEW (not started yet)';
                    break;
                case CronProcess::STATUS_RUNNING:
                    echo "RUNNING (PID: {$process->pid})";
                    break;
                case CronProcess::STATUS_FINISHED:
                    echo 'FINISHED';
                    break;
                case CronProcess::STATUS_FAILED:
                    echo 'FAILED';
                    break;
            }
            echo "\n\n";
        }
    }
}
