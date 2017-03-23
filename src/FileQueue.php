<?php
/**
 * Projet :  php-simple-queue.
 * User: mvedie
 * Date: 23/03/2017
 * Time: 15:20
 */
namespace PhpSimpleQueue;

class FileQueue implements QueueInterface{
	//	protected static $queue_file = __DIR__ . DIRECTORY_SEPARATOR . 'queue.q';
	/** @var null|string File path for the queue file */
	protected $queue_filepath = null;
	/** @var null|string File path for the queue log file */
	protected $log_filepath = null;
	/** @var null|resource Resource stored */
	protected $queue_resource = null;
	/** @var int|null Time in ms to wait before unlock the resource */
	protected $wait_before_unlock = null;
	
	/**
	 * FileQueue constructor.
	 *
	 * @param string   $queue_name
	 * @param bool     $log
	 * @param int|null $limit_exec_count    Number of execution allowed by $limit_exec_ms_count range
	 * @param int|null $limit_exec_ms_count Range of millisecond to allow $limit_exec_count process execution
	 *
	 * @throws Exception
	 */
	public function __construct( string $queue_name, bool $log = false, int $limit_exec_count = null, int $limit_exec_ms_count = null ) {
		$this->queue_filepath = __DIR__ . DIRECTORY_SEPARATOR . $queue_name . '.queue';
		if ( $log ) {
			$this->log_filepath = __DIR__ . DIRECTORY_SEPARATOR . $queue_name . '.log';
		}
		$this->queue_resource = fopen( $this->queue_filepath, "c+" );
		
		if ( $limit_exec_count && !$limit_exec_ms_count ) {
			throw new Exception( 'limit_exec_ms_count can\'t be null if limit_exec_count is provided' );
		}
		if ( $limit_exec_ms_count && !$limit_exec_count ) {
			throw new Exception( 'limit_exec_count can\'t be null if limit_exec_ms_count is provided' );
		}
		
		if ( $limit_exec_count ) {
			$this->wait_before_unlock = (int)( $limit_exec_ms_count / $limit_exec_count );
		}
	}
	
	/**
	 * Function to log information of queue.
	 *
	 * @param        $pid
	 * @param string $type
	 */
	private function log( $pid, string $type ) {
		$current_date = function() {
			// TimeZone
			$timezone = new \DateTimeZone( "UTC" );
			
			// Date actuelle
			$arr = explode( ".", microtime( true ) );
			$ts  = $arr[ 0 ];
			$ms  = isset( $arr[ 1 ] ) ? $arr[ 1 ] : 0;
			
			// list( $ts, $ms ) = explode( ".", microtime( true ) );
			return new DateTime( date( "Y-m-d H:i:s.", $ts ) . str_pad( $ms, 6, 0, STR_PAD_RIGHT ), $timezone );
		};
		
		
		$text = "$type : (PID $pid) @" . $current_date()->format( "Y-m-d G:i:s.u" ) . PHP_EOL;
		file_put_contents( $this->log_filepath, $text, FILE_APPEND );
	}
	
	/**
	 * Function to entrer in queue and execute callback if its your turn
	 *
	 * @param int       $ms_timeout Maximum time to obtain his turn in queue
	 * @param  callable $callback   Function to execute when your turn is arrived
	 *
	 * @return bool Return true if your turn is passed, false when timeout or an error occured
	 * @throws Exception
	 */
	public function enterInQueue( int $ms_timeout = 0, $callback ) {
		// test si le callback est callable
		if ( !is_callable( $callback ) ) {
			throw new Exception( 'Callback is not callable' );
		}
		// recuperation du pid courrant
		$current_pid = getmypid();
		// definition du time_max pour le timeout
		$time_max = ( $ms_timeout ) ? ( microtime( true ) * 1000000 ) + abs( $ms_timeout ) * 1000 : null;
		
		flock( $this->queue_resource, LOCK_EX );
		
		$data  = fgets( $this->queue_resource );
		$queue = unserialize( $data );
		$queue = !is_array( $queue ) ? [] : $queue;
		
		$keys = array_keys( $queue );
		end( $keys );
		$counter           = (int)current( $keys ) + 1;
		$queue[ $counter ] = $current_pid;
		
		fseek( $this->queue_resource, 0, SEEK_SET );
		$serial = serialize( $queue );
		fwrite( $this->queue_resource, $serial );
		ftruncate( $this->queue_resource, strlen( $serial ) );
		flock( $this->queue_resource, LOCK_UN );
		
		$this->log( $current_pid, 'QUEUED' );
		
		
		while ( flock( $this->queue_resource, LOCK_EX ) && ( 0 === fseek( $this->queue_resource, 0, SEEK_SET ) ) && ( $data = fgets( $this->queue_resource ) ) && ( $queue = unserialize( $data ) ) && ( current( $queue ) != $current_pid ) ) {
			// timeout
			if ( $time_max && $time_max < ( microtime( true ) * 1000000 ) ) {
				foreach ( $queue as $k => $val ) {
					if ( $val === $current_pid ) {
						unset( $queue[ $k ] );
						break;
					}
				}
				//				unset($queue[$counter]);
				fseek( $this->queue_resource, 0, SEEK_SET );
				$serial = serialize( $queue );
				fwrite( $this->queue_resource, $serial );
				ftruncate( $this->queue_resource, strlen( $serial ) );
				$this->log( $current_pid, 'TIMEOUT' );
				flock( $this->queue_resource, LOCK_UN );
				
				return false;
			}
			$dif  = $counter - key( $queue );
			$wait = (int)$dif / 5 * 1000 * 1000;
			
			flock( $this->queue_resource, LOCK_UN );
			usleep( max( 200, $wait ) );
		}
		
		$pid = pcntl_fork();
		if ( $pid ) {
			$this->log( $current_pid, 'PASSED' );
			$callback();
			
			return true;
		}
		elseif ( $pid == 0 ) {
			
			//			usleep( 200 * 1000 );
			$before = ( microtime( true ) * 1000000 );
			foreach ( $queue as $k => $val ) {
				if ( $val === $current_pid ) {
					unset( $queue[ $k ] );
					break;
				}
			}
			fseek( $this->queue_resource, 0, SEEK_SET );
			$serial = serialize( $queue );
			fwrite( $this->queue_resource, $serial );
			ftruncate( $this->queue_resource, strlen( $serial ) );
			$after = ( microtime( true ) * 1000000 );
			
			
			if ( $this->wait_before_unlock ) {
				usleep( max( $this->wait_before_unlock * 1000 - ( $after - $before ), 0 ) );
			}
			flock( $this->queue_resource, LOCK_UN );
			$this->log( $current_pid, 'REMOVED' );
			die();
		}
		elseif ( $pid == -1 ) {
			foreach ( $queue as $k => $val ) {
				if ( $val === $current_pid ) {
					unset( $queue[ $k ] );
					break;
				}
			}
			fseek( $this->queue_resource, 0, SEEK_SET );
			fwrite( $this->queue_resource, serialize( $queue ), strlen( $data ) );
			flock( $this->queue_resource, LOCK_UN );
			
			return false;
		}
		
		return true;
	}
}
