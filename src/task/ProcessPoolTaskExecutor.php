<?php

namespace mix\task;

use mix\base\BaseObject;
use mix\helpers\CoroutineHelper;
use mix\helpers\ProcessHelper;

/**
 * 进程池任务执行器类
 * @author 刘健 <coder.liu@qq.com>
 */
class ProcessPoolTaskExecutor extends BaseObject
{

    // 守护执行
    const MODE_DAEMON = 4;

    // 流水线模式
    const MODE_ASSEMBLY_LINE = 1;

    // 推送模式
    const MODE_PUSH = 2;

    // 默认信号
    const SIGNAL_NONE = 0;

    // 重启信号
    const SIGNAL_RESTART = 1;

    // 左进程完成信号
    const SIGNAL_FINISH_LEFT = 2;

    // 停止左进程信号
    const SIGNAL_STOP_LEFT = 3;

    // 停止全部进程信号
    const SIGNAL_STOP_ALL = 4;

    // 程序名称
    public $name = '';

    // 执行模式
    public $mode = 5;

    // 左进程数
    public $leftProcess = 0;

    // 中进程数
    public $centerProcess = 0;

    // 右进程数
    public $rightProcess = 0;

    // 最大执行次数
    public $maxExecutions = 16000;

    // 队列名称
    public $queueName = '';

    // 临时文件目录
    public $tempDir = '\tmp';

    // 左进程启动事件回调函数
    protected $_onLeftStart;

    // 中进程启动事件回调函数
    protected $_onCenterStart;

    // 中进程消息事件回调函数
    protected $_onCenterMessage;

    // 右进程启动事件回调函数
    protected $_onRightStart;

    // 右进程消息事件回调函数
    protected $_onRightMessage;

    // 工作进程集合
    protected $_processPool;

    // 共享内存表
    protected $_table;

    // 输出队列
    protected $_inputQueue;

    // 输出队列
    protected $_outputQueue;

    // 是否为守护模式
    protected $_isModDaemon;

    // 是否为推送模式
    protected $_isModPush;

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 创建内存表
        $table = new \Swoole\Table(1024);
        $table->column('value', \Swoole\Table::TYPE_INT);
        $table->create();
        $table->set('signal', ['value' => self::SIGNAL_NONE]);
        $this->_table = $table;
        // 模式判断
        $this->_isModDaemon = (($this->mode & self::MODE_DAEMON) === self::MODE_DAEMON);
        $this->_isModPush   = (($this->mode & self::MODE_PUSH) === self::MODE_PUSH);
        // 进程数调整
        if (!$this->_isModDaemon) {
            $this->leftProcess = 1;
        }
        if ($this->_isModPush) {
            $this->rightProcess = 0;
        }
    }

    // 启动
    public function start()
    {
        // 关闭内置协程，使 exit; 可以正常在回调中使用
        CoroutineHelper::disableCoroutine();
        // 修改进程标题
        ProcessHelper::setTitle("{$this->name} master");
        // 创建队列
        $this->createQueues();
        // 创建进程
        $this->createProcesses();
        // 信号处理
        $this->signalHandle();
        // 非守护执行模式下触发停止信号
        if (!$this->_isModDaemon) {
            // 修改信号
            $this->_table->set('signal', ['value' => self::SIGNAL_FINISH_LEFT]);
            // 异步触发停止信号
            swoole_timer_after(1, function () {
                ProcessHelper::kill(ProcessHelper::getPid());
            });
        }
    }

    // 绑定事件回调函数
    public function on($event, callable $callback)
    {
        switch ($event) {
            case 'LeftStart':
                $this->_onLeftStart = $callback;
                break;
            case 'CenterStart':
                $this->_onCenterStart = $callback;
                break;
            case 'CenterMessage':
                $this->_onCenterMessage = $callback;
                break;
            case 'RightStart':
                $this->_onRightStart = $callback;
                break;
            case 'RightMessage':
                $this->_onRightMessage = $callback;
                break;
        }
    }

    // 创建队列
    protected function createQueues()
    {
        $mode       = 2;
        $messageKey = crc32($this->queueName);
        // 输入队列
        $inputQueue = new \Swoole\Process(function () {
        });
        $inputQueue->useQueue($messageKey + 1, $mode);
        $this->_inputQueue = new InputQueue(['queue' => $inputQueue, 'tempDir' => $this->tempDir]);
        // 输出队列
        $outputQueue = new \Swoole\Process(function () {
        });
        $outputQueue->useQueue($messageKey + 2, $mode);
        $this->_outputQueue = new OutputQueue(['queue' => $outputQueue, 'tempDir' => $this->tempDir]);
    }

    // 创建进程
    protected function createProcesses()
    {
        for ($i = 0; $i < $this->leftProcess; $i++) {
            $this->createProcess('left', $i);
        }
        for ($i = 0; $i < $this->centerProcess; $i++) {
            $this->createProcess('center', $i);
        }
        for ($i = 0; $i < $this->rightProcess; $i++) {
            $this->createProcess('right', $i);
        }
    }

    // 创建进程
    protected function createProcess($processType, $workerId)
    {
        // 创建进程对象
        switch ($processType) {
            case 'left':
                $process = $this->createLeftProcess($processType, $workerId);
                break;
            case 'center':
                $process = $this->createCenterProcess($processType, $workerId);
                break;
            case 'right':
                $process = $this->createRightProcess($processType, $workerId);
                break;
        }
        // 启动
        $pid = $process->start();
        // 保存实例
        $this->_processPool[$pid] = [$processType, $workerId];
    }

    // 创建左进程
    protected function createLeftProcess($processType, $workerId)
    {
        $masterPid = ProcessHelper::getPid();
        $process   = new \Swoole\Process(function ($worker) use ($masterPid, $processType, $workerId) {
            try {
                // 修改进程名称
                ProcessHelper::setTitle("{$this->name} {$processType} #{$workerId}");
                // 创建工作者
                $leftWorker = new LeftWorker([
                    'worker'      => $worker,
                    'inputQueue'  => $this->_inputQueue,
                    'outputQueue' => $this->_outputQueue,
                    'table'       => $this->_table,
                    'masterPid'   => $masterPid,
                    'workerId'    => $workerId,
                    'workerPid'   => $worker->pid,
                ]);
                // 执行任务
                try {
                    // 执行回调
                    call_user_func($this->_onLeftStart, $leftWorker);
                } catch (\Throwable $e) {
                    // 守护模式下，休息一会，避免 CPU 出现 100%
                    if ($this->_isModDaemon) {
                        sleep(1);
                    }
                    // 抛出错误
                    throw $e;
                }
            } catch (\Throwable $e) {
                \Mix::app()->error->handleException($e);
            }
        }, false, false);
        return $process;
    }

    // 创建中进程
    protected function createCenterProcess($processType, $workerId)
    {
        $masterPid = ProcessHelper::getPid();
        $process   = new \Swoole\Process(function ($worker) use ($masterPid, $processType, $workerId) {
            try {
                // 修改进程名称
                ProcessHelper::setTitle("{$this->name} {$processType} #{$workerId}");
                // 创建工作者
                $centerWorker = new CenterWorker([
                    'worker'      => $worker,
                    'inputQueue'  => $this->_inputQueue,
                    'outputQueue' => $this->_outputQueue,
                    'table'       => $this->_table,
                    'masterPid'   => $masterPid,
                    'workerId'    => $workerId,
                    'workerPid'   => $worker->pid,
                ]);
                // 执行回调
                isset($this->_onCenterStart) and call_user_func($this->_onCenterStart, $centerWorker);
                // 循环执行任务
                for ($j = 0; $j < $this->maxExecutions; $j++) {
                    $data = $centerWorker->inputQueue->pop();
                    if (empty($data)) {
                        continue;
                    }
                    try {
                        // 执行回调
                        call_user_func($this->_onCenterMessage, $centerWorker, $data);
                    } catch (\Throwable $e) {
                        // 回退数据到消息队列
                        $centerWorker->inputQueue->push($data);
                        // 休息一会，避免 CPU 出现 100%
                        sleep(1);
                        // 抛出错误
                        throw $e;
                    }
                }
            } catch (\Throwable $e) {
                \Mix::app()->error->handleException($e);
            }
        }, false, false);
        return $process;
    }

    // 创建右进程
    protected function createRightProcess($processType, $workerId)
    {
        $masterPid = ProcessHelper::getPid();
        $process   = new \Swoole\Process(function ($worker) use ($masterPid, $processType, $workerId) {
            try {
                // 修改进程名称
                ProcessHelper::setTitle("{$this->name} {$processType} #{$workerId}");
                // 创建工作者
                $rightWorker = new RightWorker([
                    'worker'      => $worker,
                    'inputQueue'  => $this->_inputQueue,
                    'outputQueue' => $this->_outputQueue,
                    'table'       => $this->_table,
                    'masterPid'   => $masterPid,
                    'workerId'    => $workerId,
                    'workerPid'   => $worker->pid,
                ]);
                // 执行回调
                isset($this->_onRightStart) and call_user_func($this->_onRightStart, $rightWorker);
                // 循环执行任务
                for ($j = 0; $j < $this->maxExecutions; $j++) {
                    // 从进程队列中抢占一条消息
                    $data = $rightWorker->outputQueue->pop();
                    if (empty($data)) {
                        continue;
                    }
                    try {
                        // 执行回调
                        call_user_func($this->_onRightMessage, $rightWorker, $data);
                    } catch (\Throwable $e) {
                        // 回退数据到消息队列
                        $rightWorker->outputQueue->push($data);
                        // 休息一会，避免 CPU 出现 100%
                        sleep(1);
                        // 抛出错误
                        throw $e;
                    }
                }
            } catch (\Throwable $e) {
                \Mix::app()->error->handleException($e);
            }
        }, false, false);
        return $process;
    }

    // 重启进程
    protected function rebootProcess($workerPid)
    {
        // 取出进程信息
        if (!isset($this->_processPool[$workerPid])) {
            throw new \mix\exceptions\TaskException('RebootProcess Error: no pid.');
        }
        list($processType, $workerId) = $this->_processPool[$workerPid];
        // 删除旧引用
        unset($this->_processPool[$workerPid]);
        // 根据信号判断是否不重建进程
        $signal = $this->_table->get('signal', 'value');
        if (($signal == self::SIGNAL_FINISH_LEFT || $signal == self::SIGNAL_STOP_LEFT) && $processType == 'left') {
            return;
        }
        if (in_array($signal, [self::SIGNAL_RESTART, self::SIGNAL_STOP_ALL])) {
            return;
        }
        // 重建进程
        $this->createProcess($processType, $workerId);
    }

    // 信号处理
    protected function signalHandle()
    {
        // 子进程终止信号处理
        $this->subprocessExitSignalHandle();
        // 重启信号处理
        $this->restartSignalHandle();
        // 停止信号处理
        $this->stopSignalHandle();
    }

    // 子进程终止信号处理
    protected function subprocessExitSignalHandle()
    {
        // 重建子进程
        \Swoole\Process::signal(SIGCHLD, function ($signal) {
            while ($result = \swoole_process::wait(false)) {
                $workerPid = $result['pid'];
                $this->rebootProcess($workerPid);
            }
        });
    }

    // 重启信号处理
    protected function restartSignalHandle()
    {
        // 非守护执行模式下不处理该信号
        if (!$this->_isModDaemon) {
            return;
        }
        // 平滑重启
        \Swoole\Process::signal(SIGUSR1, function ($signal) {
            static $handled = false;
            // 防止重复调用
            if ($handled) {
                return;
            }
            $handled = true;
            // 修改信号
            $this->_table->set('signal', ['value' => self::SIGNAL_RESTART]);
            // 定时处理
            swoole_timer_tick(1000, function () {
                static $tickCount = 0;
                $processPool = $this->_processPool;
                // 退出主进程
                if (empty($processPool) || $tickCount++ == 1) {
                    exit;
                }
                // PUSH空数据解锁阻塞进程
                $processTypes = array_column(array_values($processPool), 0);
                foreach ($processTypes as $processType) {
                    if ($processType == 'center') {
                        $this->_inputQueue->push(null);
                    }
                    if ($processType == 'right') {
                        $this->_outputQueue->push(null);
                    }
                }
            });
        });
    }

    // 停止信号处理
    protected function stopSignalHandle()
    {
        // 停止
        \Swoole\Process::signal(SIGTERM, function ($signal) {
            static $handled = false;
            // 防止重复调用
            if ($handled) {
                return;
            }
            $handled = true;
            // 守护模式下修改信号
            if ($this->_isModDaemon) {
                $this->_table->set('signal', ['value' => self::SIGNAL_STOP_LEFT]);
            }
            // 定时处理
            swoole_timer_tick(1000, function () {
                $processPool = $this->_processPool;
                // 退出主进程
                if (empty($processPool)) {
                    exit;
                }
                // 左进程是否停止完成
                $processTypes = array_column(array_values($processPool), 0);
                if (!in_array('left', $processTypes) && $this->_inputQueue->isEmpty() && $this->_outputQueue->isEmpty()) {
                    // 修改信号
                    $this->_table->set('signal', ['value' => self::SIGNAL_STOP_ALL]);
                    // PUSH空数据解锁阻塞进程
                    foreach ($processTypes as $processType) {
                        if ($processType == 'center') {
                            $this->_inputQueue->push(null);
                        }
                        if ($processType == 'right') {
                            $this->_outputQueue->push(null);
                        }
                    }
                }
            });
        });
    }

}