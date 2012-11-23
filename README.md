#Codeigniter-jobQueue
###Job Queue based on redis
by [Marcos Sanz](http://www.mistersanz.com)

Feel free to send me an email if you have any problems or you find bugs.

##Installation
First of all, you have to install [codeigniter-redis](http://github.com/joelcox/codeigniter-redis) library.
Just copy the files from this package to the correspoding folder in your 
application folder.  For example, /application/libraries/jobs.php.  

###Loading library
In /application/config/autoload.php

    $autoload['libraries'] = array('jobs');

or

    $this -> load -> library('jobs');

##Application Usage 
Variables:
  * $at ($timestamp) => Unix timestamp when you want to execute the job
  * $queue => Name of the queue. For example: high, normal, low, mail, archive
  * $controller => Name of the controller 
  * $method => Name of the method from controller (Default: index)
  * $params => Array of params (Default: null). For example: array('param1','param2','param3')
  * $description => Description of the task (Default: null). For example: UpdateUserProfile
  * $belongTo ($user_id) => Id user from the job. Only works if $trackStatus is true (Default: null).
  * $stat => Jobs stats. For example: delayed, waiting, running, complete and failed

###Create new job
Function:

    create($queue, $controller, $method, $params, $description, $belongTo)
   
For example:

    $this -> jobs -> create('high', 'users', 'check_users', array('10'), 'checkUsers','1');

###Create new  schedule job
Function:

    create_at($at, $queue, $controller, $method, $params, $description, $belongTo)
   
For example:

    $this -> jobs -> create_at(1353456000, 'high', 'users', 'check_users', array('10'), 'checkUsers','1');

###Get size from a given queue
Function:

    get_queue_size($queue)
   
For example:

    $this -> jobs -> get_queue_size('low');

###Get size from the delayed queue
Function:

    get_delayed_queue_size()
   
For example:

    $this -> jobs -> get_delayed_queue_size();

###Get size from the delated queue in a given timestamp
Function:

    get_delayed_timestamp_size($timestamp)
   
For example:

    $this -> jobs -> get_delayed_timestamp_size(1353456000);

###Clear a queue
Function:

    clear($queue)
   
For example:

    $this -> jobs -> clear('low');

###Destroy a queue
Function:

    destroy($queue)
   
For example:

    $this -> jobs -> destroy('low');

###Remove a job
Function:

    //TODO


###Get peek from a given queue
Function:

    peek($queue)
   
For example:

    $this -> jobs -> peek('low');

###Get queues memebers
Function:

    queues()
   
For example:

    $this -> jobs -> queues();

###Get workers and current job in the worker
Function:

    get_workers()
   
For example:

    $this -> jobs -> get_workers();

###Get jobs statuses or get jobs statuses from a user
Function:

    get_statuses_jobs($user_id)
   
For example:

    $this -> jobs -> get_statuses_jobs(); or $this -> jobs -> get_statuses_jobs(2341);

###Get number of stats from a given stat
Function:

    get_stat($stat)
   
For example:

    $this -> jobs -> get_stat('running');

###Clear stat
Function:

    clear_stat()
   
For example:

    $this -> jobs -> clear_stat();

##Worker Usage
Variables:
  * $worker_name => Name of the machine or worker (Default: worker)
  * $queues => Name of the queues. For example: high
  * $interval => Seconds to sleep worker (Default: null)

###Main worker (Execute jobs)
Function:

    worker($worker_name, $queues, $interval)

###Delayed worker (Re-organize schedule jobs)
Function:

    worker_delayed($worker_name, $interval)



Thanks,  
-Marcos Sanz  
 marcossanzlatorre@gmail.com  
 @marsanla
