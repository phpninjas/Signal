Signal
======

Requirements
------------

pcntl library is REQUIRED for this to work.

Trapping Signals
----------------

    // Simple message trap
    Signal::trap(Signal::USR2, function()use(){
        echo "caught a signal!";
    });


Blocking/Unblocking Signals
---------------------------

    // Allows you to effectively ignore POSIX signals from the OS (except for KILL obviously)
    Signal::block([Signal::USR2]);
    Signal::unblock([Signal::USR2]);
