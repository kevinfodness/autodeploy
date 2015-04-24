<?php

/**
 * Contains a service class to process deployments.
 *
 * PHP Version 5.4+
 *
 * @category ContinuousIntegration
 * @package  Autodeploy
 * @author   Kevin Fodness <kevin@kevinfodness.com>
 * @license  GPLv3 http://www.gnu.org/licenses/gpl.html
 * @link     http://www.kevinfodness.com
 */

namespace App\Services;

use Log;

/**
 * A service class to process deployments.
 *
 * PHP Version 5.4+
 *
 * @category ContinuousIntegration
 * @package  Autodeploy
 * @author   Kevin Fodness <kevin@kevinfodness.com>
 * @license  GPLv3 http://www.gnu.org/licenses/gpl.html
 * @link     http://www.kevinfodness.com
 */
class Deployer extends \SebastianBergmann\Git\Git {

	/**
	 * @var string The branch to target with the deployment.
	 * @access private
	 */
	private $_branch;

	/**
	 * @var bool Whether to process a commit on updated files or not.
	 * @access private
	 */
	private $_commit = false;

	/**
	 * @var bool Whether to run a push after running a pull.
	 * @access private
	 */
	private $_push = false;

	/**
	 * @var string The full repository path.
	 * @access private
	 */
	private $_repository_path;

	/**
	 * @var array The output of the current status of the repository.
	 * @access private
	 */
	private $_status = array();

	/**
	 * Controller function. Calls parent constructor with repository path and stores targeted branch.
	 *
	 * @param string $repository_path The fully qualified path to the repository.
	 * @param string $branch The branch to target.
	 *
	 * @access public
	 * @return Deployer
	 */
	public function __construct( $repository_path, $branch ) {
		parent::__construct( $repository_path );
		$this->_repository_path = realpath( $repository_path );
		$this->_branch          = $branch;
		$this->_update_status();
	}

	/**
	 * A function to process an addition, if needed.
	 *
	 * @access private
	 * @return void
	 */
	private function _maybe_add() {
		if ( $this->_status_contains( 'Untracked files:' ) ) {
			Log::info( 'Adding untracked files.' );
			$this->execute( 'git add -A' );
			$this->_commit = true;
			$this->_update_status();
		} else {
			Log::info( 'No untracked files to add. Skipping.' );
		}
	}

	/**
	 * A function to perform a commit, if necessary.
	 *
	 * @access private
	 * @return void
	 */
	private function _maybe_commit() {
		if ( $this->_commit === true || $this->_status_contains( 'Changes not staged for commit:' ) || $this->_status_contains( 'Changes to be committed:' ) ) {
			Log::info( 'Committing changed files.' );
			$this->execute( 'git commit -am "Refreshing branch with updated files."' );
			$this->_commit = true;
			$this->_push   = true;
			$this->_update_status();
		} else {
			Log::info( 'No changed files to commit. Skipping.' );
		}
	}

	/**
	 * A function to perform a push, if necessary.
	 *
	 * @access private
	 * @return void
	 */
	private function _maybe_push() {
		if ( $this->_push === true ) {
			$this->execute( 'git push origin ' . escapeshellarg( $this->_branch ) );
		}
	}

	/**
	 * A function to perform a pull.
	 *
	 * @access private
	 * @return void
	 */
	private function _pull() {
		$this->execute( 'git pull origin ' . escapeshellarg( $this->_branch ) );
	}

	/**
	 * A function to loop through the status and determine if it contains a particular string.
	 *
	 * @param string $text The text to search for.
	 *
	 * @access private
	 * @return bool True if found, false otherwise.
	 */
	private function _status_contains( $text ) {
		foreach ( $this->_status as $line ) {
			if ( stripos( $line, $text ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A function to update the local status variable.
	 *
	 * @access private
	 * @return void
	 */
	private function _update_status() {
		$this->_status = $this->execute( 'git status' );
	}

	/**
	 * Overrides the built-in Git execute function to be compatible with Laravel.
	 *
	 * @param string $command The command to execute. Assumes all arguments have already been escaped.
	 *
	 * @access protected
	 * @return string The output of the command.
	 */
	protected function execute( $command ) {

		/* Sanitize the command, piping STDERR to STDOUT. */
		$command = escapeshellcmd( $command ) . ' 2>&1';

		/* Store the current working directory so we can change back to it. */
		$cwd = getcwd();

		/* Change working directory to the repository path. */
		chdir( $this->_repository_path );

		/* Attempt to execute the command, capturing the output and return value. */
		exec( $command, $output, $return_value );

		/* If the return value is 128, it means that the repo hasn't been configured with user.name and user.email. */
		if ( $return_value === 128 ) {

			/* Run repo config. */
			exec( 'git config user.email ' . escapeshellarg( 'www-data@' . $_SERVER['SERVER_NAME'] ) . ' 2>&1', $output, $return_value );
			exec( 'git config user.name "www-data" 2>&1', $output, $return_value );

			/* Try running the command again. */
			exec( $command, $output, $return_value );
		}

		/* Change working directory back to the original. */
		chdir( $cwd );

		/* Log any errors we came across. */
		if ( $return_value !== 0 ) {
			Log::error( 'Failed while trying to execute `' . $command . '` with exit code ' . $return_value . '. Output:' . "\n" . implode( "\n", $output ) );
		}

		return $output;
	}

	/**
	 * A function to process a deployment.
	 *
	 * @access public
	 * @return void
	 */
	public function deploy() {
		$this->_maybe_add();
		$this->_maybe_commit();
		$this->_pull();
		$this->_maybe_push();
	}

	/**
	 * A function to determine whether the currently checked out branch on the local repo matches the specified branch.
	 *
	 * @access public
	 * @return bool
	 */
	public function is_valid_deployment() {
		return ( $this->getCurrentBranch() === $this->_branch );
	}
}
