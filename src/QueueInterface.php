<?php
/**
 * Projet :  php-simple-queue.
 * User: mvedie
 * Date: 23/03/2017
 * Time: 15:20
 */

namespace PhpSimpleQueue;

interface QueueInterface {
	/**
	 * Function to entrer in queue and execute callback if its your turn
	 *
	 * @param int       $ms_timeout Maximum time to obtain his turn in queue
	 * @param  callable $callback   Function to execute when your turn is arrived
	 * @param  mixed    $output     Output of the callback function if there is one
	 *
	 * @return bool Return true if your turn is passed, false when timeout or an error occured
	 * @throws \Exception
	 */
	public function enterInQueue( int $ms_timeout = 0, $callback, &$output );
}
