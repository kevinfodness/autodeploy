<?php

/**
 * Contains a controller class to handle requests to the deployment endpoint.
 *
 * PHP Version 5.4+
 *
 * @category ContinuousIntegration
 * @package  Autodeploy
 * @author   Kevin Fodness <kevin@kevinfodness.com>
 * @license  GPLv3 http://www.gnu.org/licenses/gpl.html
 * @link     http://www.kevinfodness.com
 */

namespace App\Http\Controllers;

use App\Services\Deployer;
use Log;

/**
 * A controller class to handle requests to the deployment endpoint.
 *
 * PHP Version 5.4+
 *
 * @category ContinuousIntegration
 * @package  Autodeploy
 * @author   Kevin Fodness <kevin@kevinfodness.com>
 * @license  GPLv3 http://www.gnu.org/licenses/gpl.html
 * @link     http://www.kevinfodness.com
 */
class DeployController extends Controller {

	/* Define environments related to server names. */
	private $_url_map = array();

	/**
	 * Create a new controller instance.
	 *
	 * @return DeployController
	 */
	public function __construct() {
		$this->middleware( 'guest' );
	}

	/**
	 * A function to retrieve a branch name from an array of data about a push.
	 *
	 * @param array $data The array to look through for a branch name.
	 *
	 * @access private
	 * @return mixed An array of branch names on success, or false on failure.
	 */
	private function _get_branches( $data ) {

		$branches = array();

		/* Perform a high-level explicit branch check. */
		if ( ! empty( $data['branch'] ) ) {
			if ( ! in_array( $data['branch'], $branches ) ) {
				$branches[] = $data['branch'];
			}
		}

		/* Perform a high-level ref check. */
		if ( ! empty( $data['ref'] ) ) {
			$refs = explode( '/', $data['ref'] );
			if ( ! empty( $refs ) && is_array( $refs ) ) {
				$branch = array_pop( $refs );
				if ( ! in_array( $branch, $branches ) ) {
					$branches[] = $branch;
				}
			}
		}

		/* Loop through commit history looking for branch names. */
		if ( ! empty( $data['commits'] ) && is_array( $data['commits'] ) ) {
			foreach ( $data['commits'] as $commit ) {
				if ( ! empty( $commit['branch'] ) && ! in_array( $commit['branch'], $branches ) ) {
					$branches[] = $commit['branch'];
				}
			}
		}

		/* Loop through changes array looking for branch names (BitBucket webhooks). */
		if ( ! empty( $data['push']['changes'] ) && is_array( $data['push']['changes'] ) ) {
			foreach ( $data['push']['changes'] as $change ) {
				if ( ! empty( $change['new']['name'] ) && ! in_array( $change['new']['name'], $branches ) ) {
					$branches[] = $change['new']['name'];
				}
			}
		}

		/**
		 * If no branches have been defined, and it is a BitBucket push, configure a push to all defined environments.
		 *
		 * Rationale: When BitBucket issues a POST hook for a branch that has no bare commits - just commits from
		 * another branch - it doesn't report on which branch received the merge. Thus, we should trigger a deployment
		 * on all defined endpoints to ensure that the change was captured appropriately.
		 */
		if ( empty( $branches ) && ! empty( $data['canon_url'] ) && strpos( $data['canon_url'], 'bitbucket.org' ) >= 0 ) {
			$branches[] = 'master';
			foreach ( $this->_url_map as $destination ) {
				foreach ( $destination as $branch ) {
					if ( ! in_array( $branch, $branches ) ) {
						$branches[] = $branch;
					}
				}
			}
		}

		return ( ! empty( $branches ) ) ? $branches : false;
	}

	/**
	 * A function to get the data from the webhook payload under a variety of formats.
	 *
	 * @access private
	 * @return bool
	 */
	private function _get_data() {

		/* Attempt to extract JSON data directly from php://input. */
		$data = $this->_maybe_decode_json( @file_get_contents( 'php://input' ) );
		if ( $this->_get_repo_name( $data ) !== false && $this->_get_branches( $data ) !== false ) {
			return $data;
		}

		/* Attempt to extract JSON data from payload. */
		if ( ! empty( $data['payload'] ) ) {
			$data = $data['payload'];

			/* Determine if we need to JSON-decode the payload (should be automatic, but people be crazy). */
			if ( ! is_array( $data ) ) {
				$data = $this->_maybe_decode_json( $data );
			}

			/* Determine if data is properly formed. */
			if ( $this->_get_repo_name( $data ) !== false && $this->_get_branches( $data ) !== false ) {
				return $data;
			}
		}

		/* Attempt to extract JSON data from $_POST. */
		if ( empty( $_POST['payload'] ) ) {
			return false;
		}

		/* Attempt to decode the payload. */
		$data = $this->_maybe_decode_json( $_POST['payload'] );
		if ( $this->_get_repo_name( $data ) !== false && $this->_get_branches( $data ) !== false ) {
			return $data;
		}

		return false;
	}

	/**
	 * Processes a data block trying to get a repo name.
	 *
	 * @param array $data The array to look through for a repo name.
	 *
	 * @access private
	 * @return mixed The repo name on success, or false on failure.
	 */
	private function _get_repo_name( $data ) {

		/* Try to use repository slug, if available. */
		if ( ! empty( $data['repository']['slug'] ) ) {
			return $data['repository']['slug'];
		}

		/* Fall back to repository name. */
		if ( ! empty( $data['repository']['name'] ) ) {
			return $data['repository']['name'];
		}

		return false;
	}

	/**
	 * A function to try to decode a variable as JSON, while being a bit more lenient than the PHP parser.
	 *
	 * @param string $data The data to decode.
	 *
	 * @access private
	 * @return mixed
	 */
	private function _maybe_decode_json( $data ) {

		/* If we have already been given an array, return it as-is. */
		if ( is_array( $data ) ) {
			return $data;
		}

		/* If data is empty, don't bother. */
		if ( empty( $data ) ) {
			return false;
		}

		/* Replace references to line breaks for leniency. */
		$data = str_replace( array( "\n", "\r" ), '', $data );

		/* Try to decode. */
		$json = json_decode( $data, true );

		return ( ! empty( $json ) && is_array( $json ) ) ? $json : false;
	}

	/**
	 * A function to process the deployment.
	 *
	 * @access private
	 * @return bool True on success, false on failure.
	 */
	private function _process_deployment() {

		/* Attempt to get data. */
		$data = $this->_get_data();
		if ( empty( $data ) ) {
			return false;
		}

		/* Store repository name and branches for ease of access. */
		$repository = $this->_get_repo_name( $data );
		$branches   = $this->_get_branches( $data );

		/* Determine if repository exists on this server. */
		if ( ! is_dir( '/var/www/' . $repository ) ) {
			foreach ( $branches as $branch ) {
				$this->_route_deployment( $repository, $branch, $data );
			}

			return true;
		}

		/* Process each branch. */
		foreach ( $branches as $branch ) {

			/* Determine if current branch checked out for repository matches requested branch. */
			$repo = new Deployer( '/var/www/' . $repository, $branch );
			if ( ! $repo->is_valid_deployment() ) {
				Log::info( 'Branch "' . $branch . '" of repository ' . $repository . ' is not checked out on this system.' );
				$this->_route_deployment( $repository, $branch, $data );
				continue;
			}

			/* Process the deployment, picking up local modified files in the process, if necessary. */
			Log::info( 'Starting deployment for ' . $repository . ' on branch ' . $branch );
			$repo->deploy();
			Log::info( 'Finished deployment for ' . $repository . ' on branch ' . $branch );
		}

		return true;
	}

	/**
	 * A function to attempt to route a deployment to other servers in the network.
	 *
	 * @param string $repository The repository name to route.
	 * @param string $branch The branch name to route.
	 * @param array $data The data payload to route.
	 *
	 * @access private
	 * @return bool True on successful route, false otherwise.
	 */
	private function _route_deployment( $repository, $branch, $data ) {

		/* Have we relayed once already? */
		if ( ! empty( $data['autodeploy_relay'] ) && $data['autodeploy_relay'] === 'true' ) {
			Log::info( 'Already relayed. Dropping request for ' . $repository . ' on branch ' . $branch );

			return false;
		}

		/* Are there any servers to relay to? */
		if ( empty( $this->_url_map ) || ! is_array( $this->_url_map ) ) {
			Log::info( 'No servers configured to relay to. Dropping request for ' . $repository . ' on branch ' . $branch );

			return false;
		}

		/* Add relay element to the data array and compile to JSON payload. */
		$data['autodeploy_relay'] = 'true';
		$payload                  = json_encode( $data );

		/* Pull server names that match the environment. */
		foreach ( $this->_url_map as $server => $environments ) {

			/* Skip this server. */
			if ( $server === $_SERVER['SERVER_NAME'] ) {
				continue;
			}

			/* Relay the request if the branch matches. */
			if ( in_array( $branch, $environments ) ) {
				Log::info( 'Relaying request for ' . $repository . ' on branch ' . $branch . ' to ' . $server );
				$ch = curl_init( 'http://' . $server . '/deploy' );
				curl_setopt_array(
					$ch, array(
						CURLOPT_POST           => true,
						CURLOPT_POSTFIELDS     => array( 'payload' => $payload ),
						CURLOPT_RETURNTRANSFER => true,
					)
				);
				curl_exec( $ch );
				curl_close( $ch );
			}
		}

		Log::info( 'Finished relaying.' );

		return true;
	}

	/**
	 * A function to receive incoming deployment requests from VCS and process them.
	 *
	 * @access public
	 * @return void
	 */
	public function deploy() {

		/* Attempt to process a deployment. */
		if ( $this->_process_deployment() ) {
			return;
		}

		/* Get the contents of POST to print to the log. */
		ob_start();
		echo 'Contents of php://input JSON-decoded:' . "\n";
		print_r( $this->_maybe_decode_json( @file_get_contents( 'php://input' ) ) );
		echo 'Contents of $_POST:' . "\n";
		print_r( $_POST );
		$content = ob_get_contents();
		ob_end_clean();

		/* Write the error including the contents of $_POST. */
		Log::error( 'Missing or malformed payload:' . "\n" . $content );
	}
}
