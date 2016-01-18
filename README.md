yii-cron-tasks
=================

Simple extension to create and execute scheduled console commands in Yii Framework 1.x application.

It allows to create tasks with powerful cron syntax. Each task can be unique to disallow overlapping (with
logging warning message if this happens). Also you can set output file for each task and get information about
last start and stop time and current status of execution (with PID).

For license information check the [LICENSE](LICENSE.md) file.

Tested on Yii Framework v1.1.16.

Installation
-------------

This extension is available at packagist.org and can be installed via composer by following command:

`composer require --dev stevad/yii-cron-tasks`.

If you want to install this extension manually - copy sources to `/protected/extensions` directory.

Example of default configuration (with using of `ext.yii-cron-tasks` alias, meaning that extension files are located here: `/protected/extensions/yii-cron-tasks`):

```php
return array(
    // import classes
    'import' => array(
        'ext.yii-cron-tasks.*'
    ),
    'components' => array(
        'cron' => array(
            'class' => 'ext.yii-cron-tasks.CronService',
            // next option must be a valid PHP callback, this is example
            'tasksCallback' => array(
                array('class' => 'application.models.AppCronTasks'),
                'getList'
            ),
        ),
    ),
    'commandMap' => array(
        'cron' => array(
            'class' => 'ext.yii-cron-tasks.CronCommand'
        ),
    ),
);
```

For component option `tasksCallback` you must specify valid PHP callback. First argument can be an array with object
definition to create instance by `Yii::createComponent()` method.

In configuration example it was mentioned callback with Yii class definition `application.models.AppCronTasks` and
`getList` action. Here is the example of class content with cron tasks definitions:

File: `protected/models/AppCronTasks.php`

```php
class AppCronTasks
{
    public function getList()
    {
        $tasks = array();

        // call console command 'mail' with action 'sendInvites' every hour each 2 minutes starting from 9th
        // and save output to protected/runtime/console-mail-invites.txt
        $task1 = new CronTask('mail', 'sendInvites');
        $tasks[] = $task1
            ->name('Send invites via mail')
            ->minute('9/2')
            ->setOutputFile(Yii::app()->getRuntimePath() . '/console-mail-invites.txt');

        // call console command 'import' with action 'products' every day at 00:00 and save output
        $task2 = new CronTask('import', 'products', array('removeOld' => 1));
        $tasks[] = $task2
            ->name('Import products (daily)')
            ->daily()
            ->setOutputFile(Yii::app()->getRuntimePath() . '/product-import.txt');

        return $tasks;
    }
}
```

In this class we have method witch returns two configured console tasks. To run them we need to make last step: manually
add special console command to the server's crontab:

`* * * * * php /path/to/yiic cron daemon >> /dev/null 2>&1`

And now server will run our own cron daemon console command each minute and check if some of the specified tasks
need to be executed.


CronTask class
-------------

By default each task instance is pre-configured to be executed each minute (cron schedule: `* * * * *`). To create own
task you need to create `CronTask` instance and pass command, action names and optional params for it.

Command is the name of the available application console command. Action name can be omitted (will run default
action: `index` or another by configuration options).

Params are represented as the key-valued list where key is the name of param (without `--` at the beginning).

Available methods in `CronTask` class:

- `name('Task name')` - sets name for task (for logs and task statuses)
- `unique()` - sets task to disallow running of another instance of the same task if previous is still running
- `setOutputFile('/full/path/to/file.txt')` - sets the file to which will be redirected console output
- `canRun()` - check if task can be executed now
- `getProcessInfo()` - create `CronProcess` instance with detailed process information (last start and stop time, PID,
  status of execution)

Methods to control schedule:

- `hour(12)` - sets hour part of cron schedule
- `minute('*/5')` - sets minute part of cron schedule (in example: each five minutes, starting with 0)
- `day('1-10,15/2')` - sets day of month part of cron schedule (in example: 1-10 and then each 2 days from 15th by the
end of the month)
- `month('4')` - sets month part of cron schedule (in example: each April)
- `dayOfWeek('6')` - sets day of week part of cron schedule (in example: each Saturday)
- `cron('30 12 * * *')` - set schedule directly by cron

Each method support all features of cron syntax. Check this site for more information: [crontab.guru](http://crontab.guru/)

Also there is available some predefined "macro" methods:

- `hourly()` - run task at the beginning of each hour, equals to cron: `0 * * * *`
- `daily()` - run task each day at 00:00, equals to cron: `0 0 * * *`
- `monthly()` - run task each month at 1st day at 00:00, equals to cron: `0 0 1 * *`
- `yearly()` - run task each year at 1st January at 00:00, equals to cron: `0 0 1 1 *`
- `weekly()` - run task each Sunday at 00:00, equals to cron: `0 0 * * 0`

You can combine methods in any way. For example, to set task to be executed at 18:00 every day you can use next code:

```php
$task = new CronTask('command/action');
$task->daily()->hour(18);
```

Author
-------------

Copyright (c) 2016 by Stevad.