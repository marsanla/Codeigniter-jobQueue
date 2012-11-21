#Codeigniter-jobQueue
###Job Queue based on redis
by [Marcos Sanz](http://www.mistersanz.com)

This library is still in progress. Feel free to try and fix it.

##Installation
First of all, you have to install [codeigniter-redis](http://github.com/joelcox/codeigniter-redis) library.
Just copy the files from this package to the correspoding folder in your 
application folder.  For example, /application/libraries/jobs.php.  

##Loading library
In /application/config/autoload.php

    $autoload['libraries'] = array('jobs');

or

    $this -> load -> library('jobs');

##Usage   
###Create new job
To create a new job:
  * $queue => Name of the queue. For example: high, normal, low, mail, archive
  * $controller => Name of the controller 
  * $method => Name of the method from controller (Default: index)
  * $params => Array of params (Default: null). For example: array('param1','param2','param3')
  * $description => Description of the task (Default: null). For example: UpdateUserProfile
  * $time => Timestamp to execute the job (Default: null).
  * $trackStatus => Boolean to track the job with status (Default: true).
  * $owner => Id user from the job. Only works if $trackStatus is true (Default: null).
  * $id => Id from the task to recreate task.

Function:

    create($queue, $controller, $method, $params, $description, $time, $trackStatus, $owner, $id)
   



Feel free to send me an email if you have any problems.  


Thanks,  
-Marcos Sanz  
 marcossanzlatorre@gmail.com  
 @marsanla
