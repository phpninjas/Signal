<?php

declare(ticks=1);

namespace Signal\Test;

use Signal\Signal;

class SignalTest extends \PHPUnit_Framework_TestCase {

    /**
     * Always clear down the SIGUSR2 and reset
     * it to it's default status to avoid problems in
     * the test run where SIGUSR2 might actually get
     * sent to the running instance of phpunit test
     * suite.
     */
    public function tearDown(){
        pcntl_signal(SIGUSR2, SIG_DFL);
    }

    public function testSignalTrapping(){

        Signal::trap(SIGUSR2, function()use(&$result){
            $result = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($result);

    }

    public function testSignalHandlerNotRun(){

        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });

        // NO SIGNAL SENT SO NOT EXPECTED TO RUN.
        pcntl_signal_dispatch();

        $this->assertFalse($run);

    }

    public function testIgnoringSignals(){

        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });
        // Explicity ignore this signal after it having been set.
        Signal::trap(SIGUSR2, null);

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertFalse($run);

    }

    public function testResettingHandler(){

        $pid = pcntl_fork();
        if(0===$pid){
            // Trap
            Signal::trap(SIGUSR2, function(){});
            // Reset
            Signal::trap(SIGUSR2, SIG_DFL);
            while(true){
                sleep(1);
            }
            exit(0);
        }

        $this->assertProcessUp($pid);

        // This should trigger and dispatch the signal to the pid.
        $this->triggerAndDispatch(SIGUSR2, $pid);

        try {
            $status = $this->waitForPidExit($pid);
            $this->assertEquals(SIGUSR2, $status);
        } catch(\Exception $e){
            // kill off the pid if it failed to exit!
            posix_kill($pid, SIGKILL);
            $this->fail($e->getMessage());
        }

    }

    public function testSignalHandlerWithStringSignals(){

        $run = false;
        Signal::trap("USR2", function()use(&$run){
            $run = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($run);

    }

    public function testSignalHandlerWithClassConsts(){

        $run = false;
        Signal::trap(Signal::USR2, function()use(&$run){
            $run = true;
        });

        $this->triggerAndDispatch(SIGUSR2);

        $this->assertTrue($run);

    }


    public function testMultiSignalTrapping(){

        $i = 0;
        Signal::trap(SIGUSR2, function()use(&$i){
            $i++;
        });

        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);
        posix_kill(posix_getpid(), SIGUSR2);

        pcntl_signal_dispatch();

        $this->assertEquals(4, $i);

    }


    public function testUnknownSignal(){

        $this->setExpectedException('InvalidArgumentException');

        Signal::trap("SIGSOMETHING", null);

    }

    /**
     * @dataProvider minAndMaxBounds
     */
    public function testSignalsOutsideRange($bound){

        $this->setExpectedException('InvalidArgumentException');

        Signal::trap($bound, SIG_DFL);

    }

    public function testSignalsInsideRange(){

        // throw no exceptions
        Signal::trap(31, SIG_DFL);

        Signal::trap(1, SIG_DFL);

    }

    public function testSignalDispatchSelf(){
        $run = false;
        Signal::trap(SIGUSR2, function()use(&$run){
            $run = true;
        });

        $sign = new Signal(SIGUSR2);

        $sign->dispatch();

        $this->assertTrue($run);

    }

    public function testSignalDispatchExternal(){

        $pid = pcntl_fork();
        if($pid === 0){
            // child!
            fclose(STDOUT);
            fclose(STDERR);
            sleep(5);
            exit(0);
        }

        // Trap the signal in the parent and make the test fail if we receive the signal here.
        Signal::trap("USR2", function(){
            throw new \RuntimeException("Test failed!");
        });

        $sign = new Signal("USR2");

        $sign->dispatch($pid);

        // reap dead child correctly before checking non-existence.
        pcntl_wait($status);

        // check child no longer exists!
        exec("ps $pid", $lines);
        $this->assertFalse(count($lines) >= 2);

    }

    public function testBlockingSignals(){

        $pid = pcntl_fork();
        if(0===$pid){
            Signal::block(array(SIGTERM));
            while(true){
                // loop
                sleep(1);
            }
            exit(0);
        }

        // wait a sec because we might kill it before it becomes alive!
        $this->assertProcessUp($pid);

        $killed = posix_kill($pid, SIGTERM);
        $this->assertTrue($killed);

        // check that the process didn't get killed off!
        $this->assertProcessUp($pid);

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $this->assertEquals(0, pcntl_wexitstatus($status));

    }

    public function testUnblockingSignals(){

        pcntl_sigprocmask(SIG_BLOCK, array(SIGQUIT), $oldset);
        $pid = pcntl_fork();
        if(0===$pid){
            Signal::unblock(array(SIGQUIT));
            while(true){
                sleep(1);
            }
            exit(0);
        }
        // Put mask back on this process.
        pcntl_sigprocmask(SIG_SETMASK, $oldset);

        $this->assertProcessUp($pid);

        posix_kill($pid, SIGQUIT);
        // Clear off pending process.
        pcntl_waitpid($pid, $status);

        exec("ps $pid", $lines);
        $this->assertTrue(count($lines) < 2);

        // Check status exit was SIGQUIT
        $this->assertEquals(SIGQUIT, $status);

    }

    public function assertProcessUp($pid){
        // wait a sec because we might kill it before it becomes alive!
        $tries = 20;
        $running = false;
        while($tries-- > 0){
            exec("ps -o state $pid", $lines);
            if(count($lines)>1) {
                // expect some form of sleep state
                if(preg_match('/S[+]?/', trim($lines[1]), $matches)){
                    $running = true;
                    break;
                }
            }
            usleep(200000);
        }
        $this->assertTrue($running, "Process $pid is not up!");
    }

    /**
     * Data provider for minimum and maximum boundaries
     * @return array
     */
    public function minAndMaxBounds(){
        return array(
            array(0),
            array(32),
        );
    }

    /**
     * Helper to package up the
     * @param $signal
     */
    private function triggerAndDispatch($signal, $pid = null){

        // this is posix system safe so don't expect much from Windows!
        posix_kill($pid?:posix_getpid(), $signal);
        // Weirdly (or not i haven't decided yet) this blocks all further processing...
        pcntl_signal_dispatch();

    }

    private function waitForPidExit($pid){
        $tries = 10;
        while($tries-- > 0){
            $return = pcntl_waitpid($pid, $status, WNOHANG);
            if(0===$return){
                usleep(200000);
            } else {
                return $status;
            }
        }
        throw new \Exception("Pid did not exit in time!");
    }
}