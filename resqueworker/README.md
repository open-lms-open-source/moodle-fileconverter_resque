# Resque worker

This code includes a Resque worker at requeworker/reque_worker.php. This worker does *not* use the Moodle code base, so can be moved (along with unoconv_job.php) to a new location.

If moving, you will need to make sure to include the composer vendor directory, or specify a path with the `-a` command.

## Operation
The worker operates as a foreground script, outputting logging to the console. It should be trivial to wrap it with shell commands to change that.

The tool includes a `--help` command that explains parameters available.

Running the worker is as simple as:
```
$ php resqueworker/resque_worker.php
```
At which point the worker will start monitoring the Resque queues "unoconv" and "unoconv_batch" in that priority order. Use `cntl-c` to terminate (which may take a few seconds).

# Setup Information
No more than one worker should be used per machine - as Unoconv cannot run in parallel. But one worker can service many sites, they simply must all use the same Redis server for enqueueing jobs.

The worker node must be setup to fully execute Moodle scripts for all jobs in the queue, and is expected to have the same `dirroot` paths as the main Moodle boxes.

The worker should be run as the same user as unoconv listener (if used), and should have full permissions to the Moodle data dirs. This is because the script will callback a Moodle script as the same user, and it must be able to convert files.