<?php
if (!defined('BASEPATH'))
	exit('No direct script access allowed');

/**
 * Job Queue Library
 * Implements a reddis based job queue with scheduler jobs and user filter
 * CI Redis Lib is required
 *
 * @package		Codeigniter
 * @subpackage	Libraries
 * @category	Libraries
 * @author      Marcos Sanz Latorre <marcossanzlatorre@gmail.com>
 */

class Jobs {

	// STATUSES
	const STATUS_WAITING = 1;
	const STATUS_RUNNING = 2;
	const STATUS_FAILED = 3;
	const STATUS_COMPLETE = 4;

	/**
	 * @var array Array of statuses that are considered final/complete.
	 */
	private $complete_statuses = array(self::STATUS_FAILED, self::STATUS_COMPLETE);

	/**
	 * @var array Holds the CI instance
	 */
	private $_ci;

	/**
	 * @var int Interval to sleep in workers
	 */
	private $_interval = 5;

	/**
	 * @var int Interval to block queues when pop item
	 */
	private $_interval_block = 1;

	/**
	 * @var string Name of the job queue
	 */
	private $_queue = 'queue';

	/**
	 * @var string Name of the delayed queue
	 */
	private $_delayed_queue = 'delayed';

	/**
	 * @var string Names of the stats
	 */
	private $_delayed_stat = 'delayed';
	private $_waiting_stat = 'waiting';
	private $_running_stat = 'running';
	private $_failed_stat = 'failed';
	private $_complete_stat = 'complete';

	/**
	 * Constructor function
	 */
	public function __construct() {
		$this -> _ci = &get_instance();
		$this -> _ci -> load -> library('Redis');
		$this -> _ci -> load -> library('mcurl');
	}

	/* ------------------------------------------------------------
	 * JOBS
	 * ------------------------------------------------------------
	 */

	/**
	 * Enqueue a job for execution.
	 *
	 * @access	public
	 * @param 	string $queue The name of the queue to place the job in.
	 * @param 	string $class The name of the class that contains the code to execute the job.
	 * @param 	array $args Any optional arguments that should be passed when the job is executed.
	 * @param 	string $description Task description
	 * @param 	int $belongTo Id from the owner user
	 * @return	bool
	 */
	public function create($queue, $controller, $method = 'index', $params = null, $description = null, $belongTo = null) {
		// Validate if job is correct
		$this -> validate_job($queue, $controller);

		// Check if $params is a valid type
		if ($params !== null && !is_array($params)) {
			$params = array($params);
		}

		// Create a unique id to the task
		$id = md5(uniqid('', true));

		// Convert job data to hash
		$job = $this -> job_to_hash($queue, $id, $controller, $method, $params, $description, $belongTo);

		// Enqueue the job and check if success
		if ($this -> enqueue($queue, $job)) {

			// Add status job
			$this -> add_status_job($id, $queue, self::STATUS_WAITING, $belongTo);

			// Increment WAITING stat
			$this -> incr_stat($this -> _waiting_stat);

			// Return id
			return $id;
		}

		// Return false
		return false;
	}

	/**
	 * Enqueue a job for execution at a given timestamp.
	 *
	 * @access	public
	 * @param 	int $at Int of UNIX timestamp.
	 * @param 	string $queue The name of the queue to place the job in.
	 * @param 	string $class The name of the class that contains the code to execute the job.
	 * @param 	array $args Any optional arguments that should be passed when the job is executed.
	 * @param 	string $description Task description
	 * @param 	int $belongTo Id from the owner user
	 * @return	bool
	 */
	public function create_at($at, $queue, $controller, $method = 'index', $params = null, $description = null, $belongTo = null) {
		// Validate if job is correct
		$this -> validate_job($queue, $controller);

		// Check if $at is integer
		if (!is_int($at)) {
			$at = (int)$at;
		}

		// Check if $params is a valid type
		if ($params !== null && !is_array($params)) {
			$params = array($params);
		}

		// Create a unique id to the task
		$id = md5(uniqid('', true));

		// Convert job data to hash
		$job = $this -> job_to_hash($queue, $id, $controller, $method, $params, $description, $belongTo);

		// Enqueue the job and check if success
		if ($this -> enqueue_delayed($at, $job)) {

			// Add status job
			//$this -> add_status_job($id, $queue, self::STATUS_WAITING, $belongTo);

			// Increment DELAYED stat
			$this -> incr_stat($this -> _delayed_stat);

			// Return id
			return $id;
		}

		// Return false
		return false;
	}

	/**
	 * Return the lenght of a given queue
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @return  int Size of a queue
	 */
	public function get_queue_size($queue) {
		// Return the size from a queue
		return (int)$this -> _ci -> redis -> llen($this -> _queue . ':' . $queue);
	}

	/**
	 * Get the total number of jobs in the delayed schedule.
	 *
	 * @access  public
	 * @return int Size of delayed queue
	 */
	public function get_delayed_queue_size() {
		// Return the size from a queue
		return (int)$this -> _ci -> redis -> zcard($this -> _delayed_queue);
	}

	/**
	 * Return the lenght of the delayed queue in a given timestamp
	 *
	 * @access  public
	 * @param   int $timestamp Time to execute the job
	 * @return  int Size of delayed queue in a timestamp
	 */
	public function get_delayed_timestamp_size($timestamp) {
		// Check if $timestamp is integer
		if (!is_int($timestamp)) {
			$timestamp = (int)$timestamp;
		}

		// Return the size from a queue
		return (int)$this -> _ci -> redis -> llen($this -> _delayed_queue . ':' . $timestamp);
	}

	/**
	 * Clears a queue
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @return  bool 1 if ok, 0 if not
	 */
	public function clear($queue) {
		// Clear all jobs from a queue
		return $this -> _ci -> redis -> del($this -> _queue . ':' . $queue);
	}

	/**
	 * Destroys a queue
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @return  bool 1 if ok, 0 if not
	 */
	public function destroy($queue) {
		// Clear a queue
		$this -> clear($queue);

		// Remove a queue
		return $this -> _ci -> redis -> srem(array($this -> _queue, $queue));
	}

	/**
	 * Removes a job from the queue
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @param   array $data Data to remove
	 * @return  bool 1 if ok, 0 if not
	 */
	public function remove_job($queue, $data) {
		return $this -> _ci -> lrem(array($this -> _queue . ':' . $queue, ' 0 ', $data));
		// TODO: fix this function

		// Call to remove_status_job($id)
	}

	/**
	 * Return the peek of a queue (Top item)
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @return  array Data from the top item
	 */
	public function peek($queue) {
		$data = $this -> _ci -> redis -> lrange(array($this -> _queue . ':' . $queue, ' 0 0'));
		return json_decode($data[0]);
	}

	/**
	 * Return an array of all known queues
	 *
	 * @access  public
	 * @return  array list of queues
	 */
	public function queues() {
		return $this -> _ci -> redis -> smembers($this -> _queue);
	}

	/**
	 * Delete all jobs tables
	 *
	 * @access  public
	 * @return  array count if delete
	 */
	public function flush_jobs() {
		$results = array();

		// Get delayed
		$keys_delayed = $this -> _ci -> redis -> keys('delayed:*');
		$results = array_merge((array)$results, (array)$keys_delayed);

		// Get queue
		$keys_queue = $this -> _ci -> redis -> keys($this -> _queue . ':*');
		$results = array_merge((array)$results, (array)$keys_queue);

		// Get job
		$keys_job = $this -> _ci -> redis -> keys('job:*');
		$results = array_merge((array)$results, (array)$keys_job);

		// Get stat
		$keys_stat = $this -> _ci -> redis -> keys('stat:*');
		$results = array_merge((array)$results, (array)$keys_stat);

		// Get stat
		$keys_worker = $this -> _ci -> redis -> keys('worker:*');
		$results = array_merge((array)$results, (array)$keys_worker);

		// Select keys to delete
		$results = array_merge($results, array($this -> _queue, 'delayed', 'stat', 'job', 'worker'));
		//return $results;

		return $this -> _ci -> redis -> del($results);
	}

	/**
	 * Generate hash of all job properties to be saved in the scheduled queue.
	 *
	 * @access	private
	 * @param 	string $queue Name of the queue the job will be placed on.
	 * @param 	int $id Id of the job
	 * @param 	string $controller Name of the job controller.
	 * @param 	string $method Name of the job method.
	 * @param 	array $args Array of job arguments.
	 * @return	array
	 */

	private function job_to_hash($queue, $id, $controller, $method, $params = null, $description = null, $belongTo = null) {
		return array('controller' => $controller, 'method' => $method, 'params' => $params, 'queue' => $queue, 'id' => $id, 'description' => $description, 'belongTo' => $belongTo, );
	}

	/**
	 * Ensure that supplied job controller/queue is valid.
	 *
	 * @access	private
	 * @param	string $queue Name of queue.
	 * @param	string $controller Name of job controller.
	 * @return	bool
	 * @throws	Exception
	 */
	private function validate_job($queue, $controller) {
		if (empty($controller)) {
			throw new Exception('Jobs must be given a controller.');
		} else if (empty($queue)) {
			throw new Exception('Jobs must be put in a queue.');
		}

		return true;
	}

	/**
	 * Enqueue data in a queue
	 *
	 * @access  public
	 * @param   string $queue Name of the queue
	 * @param	string $data Data from the job to enqueue
	 * @return  bool
	 */

	private function enqueue($queue, $data) {
		// Add the queue if not exists
		// $this -> _ci -> redis -> sadd($this -> _queue . ' ' . $queue);
		$this -> _ci -> redis -> sadd(array($this -> _queue, $queue));

		// Push data to the queue
		// return $this -> _ci -> redis -> rpush($this -> _queue . ':' . $queue . ' ' . json_encode($data));
		return $this -> _ci -> redis -> rpush(array($this -> _queue . ':' . $queue,json_encode($data)));
	}

	/**
	 * Enqueue data in a delayed queue
	 *
	 * @access  public
	 * @param   int $timestamp Time to execute the job
	 * @param	string $data Data from the job to enqueue
	 * @return  bool
	 */
	private function enqueue_delayed($timestamp, $data) {
		// Add the queue if not exists
		$this -> _ci -> redis -> zadd(array($this -> _delayed_queue, $timestamp, $timestamp));

		// Push data to the delayed queue
		return $this -> _ci -> redis -> rpush(array($this -> _delayed_queue . ':' . $timestamp,json_encode($data)));
	}

	/**
	 * Find the first timestamp in the delayed schedule before/including the timestamp.
	 *
	 * Will find and return the first timestamp upto and including the given
	 * timestamp. This will make sure that any jobs scheduled for the past
	 * when the worker wasn't running are also queued up.
	 *
	 * @access  private
	 * @param 	int $timestamp UNIX timestamp. Defaults to time().
	 * @return 	int|false UNIX timestamp, or false if nothing to run.
	 */
	private function next_delayed_timestamp($at = null) {
		// Check if $at exists
		if ($at === null) {
			$at = time();
		} else {
			// Check if $at is integer
			if (!is_int($at)) {
				$at = (int)$at;
			}
		}

		// Get elements
		$items = $this -> _ci -> redis -> zrangebyscore(array($this -> _delayed_queue, ' -inf ', $at, ' LIMIT 0 1'));

		// Check if there are any item
		if (!empty($items)) {
			return $items[0];
		}

		// Return false
		return false;
	}

	/**
	 * Dequeue from a given queue
	 *
	 * @access  private
	 * @param   string $queue Name of the queue
	 * @return  bool
	 */
	private function dequeue($queue) {
		// Check if queues is an array
		if (is_array($queue)) {
			// Prefix elements from array
			foreach ($queue as &$value) {
				$value = $this -> _queue . ':' . $value;
			}

			// Implode array elements
			$list_queues = implode(' ', $queue);
		} else {
			// Get queue
			$list_queues = $this -> _queue . ':' . $queue;
		}

		// Pop first job from a queue
		$data = $this -> _ci -> redis -> blpop(array($list_queues, $this -> _interval_block));

		// Check if exists
		if (!$data) {
			return FALSE;
		}

		// Return array with key value and job information
		return array($data[0], json_decode($data[1], true));
	}

	/**
	 * Pop a job off the delayed queue for a given timestamp.
	 *
	 * @access  private
	 * @param int $timestamp UNIX timestamp.
	 * @return array Matching job at timestamp.
	 */
	private function dequeue_delayed($timestamp) {
		// Set key
		$key = $this -> _delayed_queue . ':' . $timestamp;

		// Pop first job from a queue
		$data = $this -> _ci -> redis -> blpop(array($key, $this -> _interval_block));

		// Clean up if the list from $timestamp is empty
		$this -> clean_up_timestamp($key, $timestamp);

		// Check if exists
		if (!$data) {
			return FALSE;
		}

		// Return array with key value and job information
		return array($data[0], json_decode($data[1], true));
	}

	/**
	 * If there are no jobs for a given key/timestamp, delete references to it.
	 *
	 * Used internally to remove empty delayed: items in Redis when there are
	 * no more jobs left to run at that timestamp.
	 *
	 * @access  private
	 * @param	string $key Key to count number of items at.
	 * @param	int $timestamp Matching timestamp for $key.
	 */
	private function clean_up_timestamp($key, $timestamp) {
		// Check if $timestamp is integer
		if (!is_int($timestamp)) {
			$timestamp = (int)$timestamp;
		}

		// Check if timestamp queue is empty
		if ($this -> _ci -> redis -> llen($key) == 0) {
			// Delete timestamp queue
			$this -> _ci -> redis -> del($key);

			// Delete timestamp queue
			$this -> _ci -> redis -> zrem(array($this -> _delayed_queue ,$timestamp));
		}
	}

	/* ------------------------------------------------------------
	 * WORKERS
	 * ------------------------------------------------------------
	 */

	/**
	 * Redistributing work in the corresponding queues
	 *
	 * @access  public
	 * @param   string $worker_name Worker name
	 * @param	int $interval Interval to sleep
	 * @return  void
	 */
	public function worker_delayed($worker_name = 'delayed', $interval = null) {

		// Check if interval exists
		if ($interval !== null) {
			$this -> _interval = $interval;
		}

		// Log initialized worked
		log_message('debug', 'Delayed Worker <' . $worker_name . '> initialized.');

		// Start infinite loop
		while (true) {

			try {

				// Do loop while there are timestamp item in the queue
				while (($timestamp = $this -> next_delayed_timestamp()) !== false) {

					// Log processing worked
					log_message('debug', 'Delayed Worker <' . $worker_name . '>: Processing delayed items from ' . $timestamp . '.');

					// Initialize item
					$item = null;

					// Do loop while there are job under the current timestamp
					while (($job = $this -> dequeue_delayed($timestamp)) !== false) {

						$job_queue = $job[1]['queue'];
						$job_controller = $job[1]['controller'];
						$job_method = $job[1]['method'];
						$job_params = $job[1]['params'];
						$job_description = $job[1]['description'];
						$job_belongTo = $job[1]['belongTo'];

						// Log queueing worked
						log_message('debug', 'Delayed Worker <' . $worker_name . '>: Queueing ' . $job_description . ' in ' . $job_queue . '.');

						// Decrement DELAYED stat
						$this -> decr_stat($this -> _delayed_stat);

						// Enqueue job in correct queue
						$this -> create($job_queue, $job_controller, $job_method, $job_params, $job_description, $job_belongTo);

					}

				}

			} catch (Exception $e) {
				// Log error
				log_message('error', 'Exception in worker delayed <' . $worker_name . '>: ' . $e -> getMessage());
			}

			// Sleep worker during interval
			sleep($this -> _interval);
		}

		// Log finish worked
		log_message('debug', 'Delayed Worker ' . $worker_name . ' finished.');

	}

	/**
	 * Get some work done from queues (Order is important)
	 *
	 * @access  public
	 * @param   string $worker_name Worker name
	 * @param   string $queue Name of the queue
	 * @param	int $interval Interval to sleep
	 * @return  void
	 */
	public function worker($worker_name = 'worker', $queues, $interval = null) {

		// Check if queues exists
		if ($queues === null) {
			return false;
		}

		// Check if queues is an array
		if (!is_array($queues)) {
			$queues = array($queues);
		}

		// Check if interval exists
		if ($interval !== null) {
			$this -> _interval = $interval;
		}

		// Log initialized worked
		log_message('debug', 'Worker <' . $worker_name . '> initialized.');

		// Start infinite loop
		while (true) {

			try {

				// Attempt to find a job
				$job = false;

				// Check if queues is not empty
				if (!empty($queues)) {

					// Dequeue a job
					$job = $this -> dequeue($queues);

				}

				// Check exist job
				if (!$job) {

					// If no job was found, we sleep for $interval before continuing and checking again
					log_message('debug', 'Worker <' . $worker_name . '>: Waiting for -> ' . implode(',', $queues));

					// Sleep worker during interval
					sleep($this -> _interval);

					continue;

				}

				// Decrement WAITING stat
				$this -> decr_stat($this -> _waiting_stat);

				$description = $job[1]['description'];
				$queue = $job[1]['queue'];

				// Log got message
				log_message('debug', 'Worker <' . $worker_name . '>: Got a Job -> ' . $description  . ' on queue: ' . $queue);

				// Set current job in the worker
				$this -> set_worker_current_job($worker_name, $queue, $description);

				// Perform the job
				$perform = $this -> perform($job[1]);

			} catch (Exception $e) {
				// Log error
				log_message('error', 'Exception in worker <' . $worker_name . '>: ' . $e -> getMessage());
			}

		}

		// Log finish worked
		log_message('debug', 'Worker <' . $worker_name . '> finished.');

	}

	/**
	 * Process a single job.
	 *
	 * @access  private
	 * @param   array $job The job to be processed.
	 * @return  bool true if job is complete, false if job is failed
	 */
	private function perform(array $job) {

		// Update status to RUNNING
		$this -> update_status_job($job['id'], self::STATUS_RUNNING);

		// Increment RUNNING stat
		$this -> incr_stat($this -> _running_stat);

		try {

			// Create url from codeigniter
			$params = (isset($job['params'])) ? implode('/', $job['params']) : null;
			$url = array($job['controller'], $job['method'], $params);
			$url = implode('/', $url);

			// Execute job
			$this -> _ci -> mcurl -> add_call('perform_job', 'post', site_url($url));
			$exec_job = $this -> _ci -> mcurl -> execute();

			// Get data
			if (isset($exec_job['perform_job']['error'])) {
				// If there is any error trhow an exception
				throw new Exception('Job executions had an error.');
			}

			// Decrement RUNNING stat
			$this -> decr_stat($this -> _running_stat);

			// Log complete perform
			log_message('debug', 'Sucess processing job <' . $job['description'] . '> -> ' . $url);

			// Update state from this job to fail
			$this -> update_status_job($job['id'], self::STATUS_COMPLETE);

			// Increment COMPLETE stat
			$this -> incr_stat($this -> _complete_stat);

			// Return true
			return TRUE;

		} catch(Exception $e) {

			// Decrement RUNNING stat
			$this -> decr_stat($this -> _running_stat);

			// Log fail perform
			log_message('error', 'Fail processing job <' . $job['description'] . '> in <' . $job['queue'] . '> queue: ' . $e -> getMessage());

			// Update state from this job to fail
			$this -> update_status_job($job['id'], self::STATUS_FAILED);

			// Increment FAILED stat
			$this -> incr_stat($this -> _failed_stat);

			// Return false
			return FALSE;
		}
	}

	/**
	 * Get array with current workers and jobs processing
	 *
	 * @access  public
	 * @return  array $data List of workers
	 */
	public function get_workers() {
		// Get Keys of the workers
		$keys = $this -> _ci -> redis -> keys('worker:*');

		// Check if not empty
		if (!empty($keys)) {
			$data = array();
			foreach ($keys as $value) {
				// Extract worker_name
				list(, $worker_name) = array_pad(explode(':', $value), 2, null);

				// Decode json data
				$key = json_decode($this -> _ci -> redis -> get($value));

				// Create array to show
				array_push($data, array('name' => $worker_name, 'queue' => $key -> queue, 'run_at' => $key -> run_at, 'description' => $key -> description, ));
			}
			// Return array data
			return $data;
		}

		// Return false
		return FALSE;
	}

	/**
	 * Set current job in a worker
	 *
	 * @access  private
	 * @param   string $worker_name Worker name
	 * @param   string $queue Name of the queue
	 * @param   string $description	Description of the job
	 * @return  bool 1 if ok, 0 if not
	 */
	private function set_worker_current_job($worker_name, $queue, $description) {
		// Create hash of data
		$data = array('queue' => $queue, 'run_at' => time(), 'description' => $description);

		// Set current job from the worker
		return $this -> _ci -> redis -> set(array('worker:' . $worker_name,json_encode($data)));
	}

	/* ------------------------------------------------------------
	 * STATUSES
	 * ------------------------------------------------------------
	 * Save start, update, status information from a job
	 *
	 * It is create when a job is add to a queue (Not delayed queue)
	 *
	 */

	/**
	 * Get array with current statuses jobs from user or system
	 *
	 * @access  public
	 * @param   int	$id	Id from owner user
	 * @return  bool|array $data Array with all current jobs
	 */
	public function get_statuses_jobs($user_id = null) {
		// Check if $id is set
		$search = (isset($user_id)) ? 'job:*:user' . $user_id : 'job:*';

		// Get Keys of the jobs
		$keys = $this -> _ci -> redis -> keys($search);

		// Check if not empty
		if (!empty($keys)) {
			$data = array();
			foreach ($keys as $value) {
				// Extract job id and user
				list(, $job_id, $user) = array_pad(explode(':', $value), 3, null);

				// Check if user_id is not false
				$user_id = str_replace('user', '', $user);

				// Decode json data
				$key = json_decode($this -> _ci -> redis -> get($value));

				// Create array to show
				array_push($data, array('id' => $job_id, 'user_id' => $user_id, 'queue' => $key -> queue, 'status' => $key -> status, 'updated' => $key -> updated, 'started' => $key -> started, ));
			}
			// Return array data
			return $data;
		}

		// Return false
		return FALSE;
	}

	/**
	 * Add status from a job
	 *
	 * @access  private
	 * @param   int	$id Id from the job
	 * @param   string $queue Name of the queue
	 * @param	int $status Status of the job
	 * @param	int $belongTo id from the user who create the job
	 * @return  bool 1 if correct, 0 if not
	 */
	private function add_status_job($id, $queue, $status = self::STATUS_WAITING, $belongTo = null) {
		// Check if owner exists
		$belongTo = (isset($belongTo)) ? ':user' . $belongTo : '';

		// Create a key to this job
		$statusPacket = array('status' => self::STATUS_WAITING, 'queue' => $queue, 'updated' => time(), 'started' => time());
		return $this -> _ci -> redis -> set(array('job:' . $id . $belongTo, json_encode($statusPacket)));
	}

	/**
	 * Update status from a job
	 *
	 * @access  private
	 * @param   int	$id Id from the job
	 * @param	int $status Status of the job
	 * @return  bool true if correct, false if not
	 */
	private function update_status_job($id, $status) {
		// Get Key of the job
		$key = $this -> _ci -> redis -> keys("job:$id*");

		// Check if $key exists
		if (!empty($key)) {
			// Get value from key
			$value = json_decode($this -> _ci -> redis -> get($key[0]));

			// Set status packet
			$statusPacket = array('status' => $status, 'queue' => $value -> queue, 'updated' => time(), 'started' => $value -> started);

			$this -> _ci -> redis -> set(array($key[0], json_encode($statusPacket)));

			// Expire the status for completed jobs after 24 hours
			if (in_array($status, $this -> complete_statuses)) {
				$this -> _ci -> redis -> expire(array($key[0], '86400'));
			}

			// Return true
			return TRUE;
		}

		// Return false
		return FALSE;
	}

	/**
	 * Remove status monitoring from a job
	 *
	 * @access  private
	 * @param   int	$id Id from the job
	 * @return  bool|int Number of keys deleted
	 */
	private function remove_status_job($id) {
		// Get Key of the job
		$key = $this -> _ci -> redis -> keys("job:$id*");

		// Check if $key exists
		if (!empty($key)) {
			// Delete value from key
			return $this -> _ci -> redis -> del($key[0]);
		}

		// Return false
		return FALSE;
	}

	/* ------------------------------------------------------------
	 * STATS
	 * ------------------------------------------------------------
	 * Keep number of jobs in each stat. Default: 'delayed','waiting','running','complete','failed'
	 *
	 * For example: 'stat:running' -> 23 (JOBS)
	 *
	 */

	/**
	 * Get stat queue
	 *
	 * @since   0.1
	 * @access  public
	 * @param   string	$stat Name of the stat
	 * @return  int Counter of the stat
	 */
	public function get_stat($stat) {
		return (int)$this -> _ci -> redis -> get('stat:' . $stat);
	}

	/**
	 * Clear stat queue
	 *
	 * @access  public
	 * @param   string	$stat Name of the stat
	 * @return  bool 1 if ok, 0 if not
	 */
	public function clear_stat($stat) {
		return (bool)$this -> _ci -> redis -> del('stat:' . $stat);
	}

	/**
	 * Increment stat queue
	 *
	 * @access  private
	 * @param   string	$stat Name of the stat
	 * @return  bool 1 if ok, 0 if not
	 */
	private function incr_stat($stat, $by = 1) {
		return (bool)$this -> _ci -> redis -> incrby(array('stat:' . $stat, $by));
	}

	/**
	 * Decrement stat queue
	 *
	 * @access  private
	 * @param   string	$stat Name of the stat
	 * @return  bool 1 if ok, 0 if not
	 */
	private function decr_stat($stat, $by = 1) {
		return (bool)$this -> _ci -> redis -> decrby(array('stat:' . $stat, $by));
	}

}

/* End of file jobs.php */
/* Location: ./application/libraries/jobs.php */
