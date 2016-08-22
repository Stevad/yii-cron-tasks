<?php

/**
 * CronTask is the class with details of the scheduled application console task.
 *
 * It has all necessary methods to set time schedule of the task. By default schedule is equal to original
 * cron's: '* * * * *' that means to run specified console command every minute.
 *
 * Original cron syntax is supported for methods hour(), minute(), day(), month(), dayOfWeek(), cron().
 *
 * By calling `unique()` method you can set flag for task to be unique. It means that if task already running and
 * it's time to run it again - new instance will not be executed (warning message would be added to logs).
 *
 * You can combine any methods to set desired schedule. For example, if you want to run command 'import' with
 * action 'products' and params '--updateAll=1' every day at 18:00, you need to create next task instance:
 *
 * $task = new CronTask('import', 'products', array('updateAll' => 1));
 * $task->name('Import products (daily@18:00)')->daily()->hour(18);
 *
 * How to add and run task see CronService class description.
 *
 * @author Vadym Stepanov <vadim.stepanov.ua@gmail.com>
 * @date 18.01.2016
 */
class CronTask
{
    const MONTH_JANUARY = 1;
    const MONTH_FEBRUARY = 2;
    const MONTH_MARCH = 3;
    const MONTH_APRIL = 4;
    const MONTH_MAY = 5;
    const MONTH_JUNE = 6;
    const MONTH_JULY = 7;
    const MONTH_AUGUST = 8;
    const MONTH_SEPTEMBER = 9;
    const MONTH_OCTOBER = 10;
    const MONTH_NOVEMBER = 11;
    const MONTH_DECEMBER = 12;

    const WEEK_MONDAY = 0;
    const WEEK_TUESDAY = 1;
    const WEEK_WEDNESDAY = 2;
    const WEEK_THURSDAY = 3;
    const WEEK_FRIDAY = 4;
    const WEEK_SATURDAY = 5;
    const WEEK_SUNDAY = 6;

    /**
     * @var string console controller of command to execute. Required
     */
    private $command;

    /**
     * @var string action of console controller of command to execute. Can be omitted
     */
    private $action;

    /**
     * @var array key-valued list of params for specified command
     */
    private $params = array();

    /**
     * @var string optional name of the task (used in displaying of the task statuses and logs). Optional.
     */
    private $name;

    /**
     * @var bool the flag to set uniqueness of the task (to disallow overlapping)
     */
    private $unique = false;

    /**
     * @var string the name of the output file with full path to output task process
     */
    private $outputFile;

    /**
     * @var string minute value for cron schedule. Valid range: 0-59. Default: '*', means 'every minute'.
     */
    private $minuteValue = '*';

    /**
     * @var string hour value for cron schedule. Valid range: 0-23. Default: '*', means 'every hour'.
     */
    private $hourValue = '*';

    /**
     * @var string day of month value for cron schedule. Valid range: 1-31. Default: '*', means 'every day'.
     */
    private $dayValue = '*';

    /**
     * @var string month value for cron schedule. Valid range: 1-12. Default: '*', means 'every month'.
     */
    private $monthValue = '*';

    /**
     * @var string day of week value for cron schedule. Valid range: 0-6, 0 for Sunday, 6 for Saturday. Default: '*',
     * means 'any day of week'.
     */
    private $dayOfWeekValue = '*';

    /**
     * @var CronProcess information about status and last start/stop time
     */
    private $_process;

    /**
     * @var array calculated values of allowed minutes, hours, days, etc.
     */
    private $_allowedValues = array();

    /**
     * Create default task, check command and params
     * @param string $command
     * @param string|null $action
     * @param array $params
     * @throws InvalidArgumentException
     */
    public function __construct($command, $action = null, array $params = array())
    {
        if (empty($command)) {
            throw new InvalidArgumentException(
                'Command cannot be empty. You should specify name of the application console command'
            );
        }

        if (!preg_match('/^[a-z0-9]+$/i', $command)) {
            throw new InvalidArgumentException(
                'Specified command value is not valid. Valid examples: myConsole, import'
            );
        }

        if (!empty($action) && !preg_match('/^[a-z0-9]+$/i', $action)) {
            throw new InvalidArgumentException(
                'Specified action value is not valid. Valid examples: run, index, start'
            );
        }

        $this->command = $command;
        $this->action = $action;

        $clearedParams = array();
        foreach ($params as $key => $value) {
            if (!preg_match('/^[a-z]+\w+$/i', $key)) {
                throw new InvalidArgumentException(
                    "Bad param name: '{$key}'. It must contain alphanumeric values and/or underscore."
                );
            }
            $clearedParams[$key] = $value;
        }

        $this->params = $clearedParams;
    }

    /**
     * Get console command
     * @return string
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * Get console command action
     * @return string
     */
    public function getCommandAction()
    {
        return $this->action;
    }

    /**
     * Get list of params (already cleared and escaped)
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Get unique identifier for current task
     * @param string $hashFunc the name of the PHP hash function
     * @return string
     */
    public function getId($hashFunc = 'sha1')
    {
        if (!function_exists($hashFunc)) {
            throw new RuntimeException(
                "Your server does not have hash function '{$hashFunc}'. Use another one: md5, sha1, crc32"
            );
        }

        return $hashFunc(json_encode(array(
            $this->command,
            $this->action,
            $this->params,
        )));
    }

    /**
     * Get name of the task. If not specified, identifier will be returned.
     * @return string
     */
    public function getName()
    {
        return $this->name ?: $this->getId();
    }

    /**
     * Get output file
     * @return string
     */
    public function getOutputFile()
    {
        return $this->outputFile;
    }

    /**
     * Get cron schedule
     * @return string
     */
    public function getCron()
    {
        return "{$this->minuteValue} {$this->hourValue} {$this->dayValue} {$this->monthValue} {$this->dayOfWeekValue}";
    }

    /**
     * Check if task instances cannot be overlapped
     * @return bool
     */
    public function isUnique()
    {
        return $this->unique;
    }

    /**
     * Set task to be unique to prevent overlapping
     * @return $this
     */
    public function unique()
    {
        $this->unique = true;

        return $this;
    }

    /**
     * Set name of the task
     * @param string $value
     * @return $this
     */
    public function name($value)
    {
        $this->name = trim($value);

        return $this;
    }

    /**
     * Set hour schedule of the task.
     * @param string $value
     * @return $this
     */
    public function hour($value)
    {
        $this->hourValue = $this->parseValue('hour', (string)$value);

        return $this;
    }

    /**
     * Set minute schedule of the task
     * @param string $value
     * @return $this
     */
    public function minute($value)
    {
        $this->minuteValue = $this->parseValue('minute', (string)$value);

        return $this;
    }

    /**
     * Set day of month schedule of the task
     * @param string $value
     * @return $this
     */
    public function day($value)
    {
        $this->dayValue = $this->parseValue('day', (string)$value);

        return $this;
    }

    /**
     * Set month schedule of the task
     * @param string $value
     * @return $this
     */
    public function month($value)
    {
        $this->monthValue = $this->parseValue('month', (string)$value);

        return $this;
    }

    /**
     * Set day of week schedule of the task
     * @param string $value
     * @return $this
     */
    public function dayOfWeek($value)
    {
        $this->dayOfWeekValue = $this->parseValue('dayOfWeek', (string)$value);

        return $this;
    }

    /**
     * Macro method to run task at the beginning of the each hour (at 10:00, 11:00, ...)
     * @return $this
     */
    public function hourly()
    {
        return $this->cron('0 * * * *');
    }

    /**
     * Macro method to run task at the beginning of the each day
     * @return $this
     */
    public function daily()
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Macro method to run task on the 1st day of each month
     * @return $this
     */
    public function monthly()
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * Macro method to run task on the 1st day of each year
     * @return $this
     */
    public function yearly()
    {
        return $this->cron('0 0 1 1 *');
    }

    /**
     * Macro method to run task on each Sunday at 00:00
     * @return $this
     */
    public function weekly()
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * Set schedule by cron value
     * @param string $value
     * @return $this
     * @throws InvalidArgumentException
     */
    public function cron($value)
    {
        $parts = array('minute', 'hour', 'day', 'month', 'dayOfWeek');
        $partPattern = '[\d\/\-\,\*]+';
        foreach ($parts as &$part) {
            $part = "(?P<{$part}>{$partPattern})";
        }
        unset($part);

        $regexp = '/^' . implode('\s', $parts) . '$/';
        preg_match($regexp, $value, $matches);

        if (count($matches) === 0) {
            throw new InvalidArgumentException("Bad cron expression: {$value}");
        }

        $this
            ->minute($matches['minute'])
            ->hour($matches['hour'])
            ->day($matches['day'])
            ->month($matches['month'])
            ->dayOfWeek($matches['dayOfWeek']);

        return $this;
    }

    /**
     * Set output file to store any output from task console command
     * @param string $filePath
     * @return $this
     * @throws InvalidArgumentException
     */
    public function setOutputFile($filePath)
    {
        if (is_dir($filePath)) {
            throw new InvalidArgumentException('Wrong output path - this is the directory!');
        }

        $dirName = dirname($filePath);

        if (!is_dir($dirName)) {
            throw new InvalidArgumentException('Wrong output path - target directory for output file does not exist!');
        }

        if (!is_writable($dirName)) {
            throw new InvalidArgumentException('Wrong output path - target directory for output file does not writable!');
        }

        $this->outputFile = $filePath;

        return $this;
    }

    /**
     * Check if task can be executed now.
     * @return bool
     */
    public function canRun()
    {
        $date = getdate();
        $dateValues = array(
            'minute' => $date['minutes'],
            'hour' => $date['hours'],
            'day' => $date['mday'],
            'month' => $date['mon'],
            'dayOfWeek' => $date['wday'],
        );

        $canRun = array();

        foreach ($dateValues as $field => $value) {
            if (!array_key_exists($field, $this->_allowedValues)) {
                $fieldName = $field . 'Value';
                $this->parseValue($field, $this->$fieldName);
            }

            $canRun[$field] = in_array($value, $this->_allowedValues[$field], true);
        }

        return $canRun['minute'] && $canRun['hour'] && $canRun['month'] && $canRun['day'] && $canRun['dayOfWeek'];
    }

    /**
     * Create process instance with additional information
     * @return CronProcess
     */
    public function getProcessInfo()
    {
        if ($this->_process === null) {
            $this->_process = CronProcess::createByTask($this, Yii::app()->cron);
        }

        return $this->_process;
    }

    /**
     * Parse value of the specified field in cron schedule and get allowed date/time values (minutes, hours, ...)
     * @param string $field name of the field of cron schedule: 'hour', 'minute', ...
     * @param string $value value for field
     * @return string
     * @throws InvalidArgumentException
     */
    private function parseValue($field, $value)
    {
        $regexp = '(\,|^)(?P<arg1>\*|%DIGIT%)(\-(?P<arg2>%DIGIT%))?(\/(?P<step>%DIGIT%))?';

        $digitRegexp = '';
        switch ($field) {
            case 'hour':
                $digitRegexp = '[0-2]?[0-9]+';
                break;
            case 'minute':
                $digitRegexp = '[0-5]?[0-9]+';
                break;
            case 'day':
                $digitRegexp = '[1-3]?[0-9]+';
                break;
            case 'month':
                $digitRegexp = '1?[0-9]+';
                break;
            case 'dayOfWeek':
                $digitRegexp = '[0-6]+';
                break;
        }

        $regexp = str_replace('%DIGIT%', $digitRegexp, $regexp);
        preg_match_all("/{$regexp}/", $value, $matches);

        $num = count($matches['arg1']);
        if ($num === 0) {
            throw new InvalidArgumentException("Bad syntax for '{$field}' (value: '{$value}')");
        }

        $analyze = array();
        for ($i = 0; $i < $num; $i++) {
            $analyze[] = array(
                'arg1' => $matches['arg1'][$i],
                'arg2' => $matches['arg2'][$i],
                'step' => $matches['step'][$i],
            );
        }

        $this->getAllowedValues($field, $analyze);

        return $value;
    }

    /**
     * Get all allowed values for parsed field conditions
     * @param string $field name of the field of cron schedule
     * @param array $matches parsed range conditions from entered value
     * @throws InvalidArgumentException
     */
    private function getAllowedValues($field, $matches)
    {
        $sequence = array();
        foreach ($matches as $match) {

            if ($match['arg1'] === '*' && $match['arg2'] !== '') {
                throw new InvalidArgumentException(
                    "Bad syntax for '{$field}' (part of value: '{$match['arg1']}-{$match['arg2']}')"
                );
            }

            try {
                $sequence = array_merge(
                    $sequence,
                    $this->generateSequence($field, $match['arg1'], $match['arg2'], $match['step'])
                );
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    "Bad syntax for '{$field}' (part of value: '{$match['arg1']}-{$match['arg2']}'). Error: {$e->getMessage()}"
                );
            }
        }

        $this->_allowedValues[$field] = $sequence;
    }

    /**
     * Generate sequence of the allowed values for specified range with some step
     * @param string $field name of the field of cron schedule
     * @param mixed $start start of the range or '*' (means, all available values)
     * @param mixed $end end of the range (can be omitted)
     * @param mixed $step step of value increasing in range
     * @return array
     * @throws InvalidArgumentException
     */
    private function generateSequence($field, $start, $end, $step)
    {
        $fieldLimits = array(
            'hour' => array('start' => 0, 'end' => 23),
            'minute' => array('start' => 0, 'end' => 59),
            'day' => array('start' => 1, 'end' => 31),
            'month' => array('start' => 1, 'end' => 12),
            'dayOfWeek' => array('start' => 0, 'end' => 6),
        );

        $step = $step ? (int)$step : 1;

        if ($start === '*') {
            $start = $fieldLimits[$field]['start'];
            $end = $fieldLimits[$field]['end'];
        } else {
            $start = (int)$start;
            if ($start < $fieldLimits[$field]['start']) {
                throw new InvalidArgumentException("Wrong beginning of the range: '{$start}'");
            }

            if (empty($end)) {
                $end = $start;
                if ($step > 1) {
                    $end = $fieldLimits[$field]['end'];
                }
            } else {
                $end = (int)$end;
            }
            if ($end > $fieldLimits[$field]['end']) {
                throw new InvalidArgumentException("Wrong ending of the range: '{$end}'");
            }
        }

        $values = array();
        for ($i = $start; $i <= $end; $i += $step) {
            $values[] = $i;
        }

        return $values;
    }
}
