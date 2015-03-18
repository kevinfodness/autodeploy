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
		var_dump( $_POST );
		var_dump( json_decode( @file_get_contents( 'php://input' ), true ) );
		$content = ob_get_contents();
		ob_end_clean();

		/* Write the error including the contents of $_POST. */
		Log::error( 'Missing or malformed payload:' . "\n" . $content );
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

		/* Extract name of branch from $data['ref'] as last element. */
		$refs = explode( '/', $data['ref'] );
		if ( empty( $refs ) ) {
			return false;
		}

		/* Store repository name and branch name for ease of access. */
		$repository = $data['repository']['name'];
		$branch     = array_pop( $refs );

		/* Determine if repository exists on this server. */
		if ( ! is_dir( '/var/www/' . $repository ) ) {
			return $this->_route_deployment( $repository, $branch, $data );
		}

		/* Determine if current branch checked out for repository matches requested branch. */
		$repo = new Deployer( '/var/www/' . $repository, $branch );
		if ( ! $repo->is_valid_deployment() ) {
			Log::info( 'Branch "' . $branch . '" of repository ' . $repository . ' is not checked out on this system.' );
			$this->_route_deployment( $repository, $branch, $data );

			return false;
		}

		/* Process the deployment, picking up local modified files in the process, if necessary. */
		Log::info( 'Starting deployment for ' . $repository . ' on branch ' . $branch );
		$repo->deploy();
		Log::info( 'Finished deployment for ' . $repository . ' on branch ' . $branch );

		return true;
	}

	/**
	 * A function to get the data
	 * @return bool
	 */
	private function _get_data() {

		/* Attempt to extract JSON data directly from php://input. */
		$data = json_decode( @file_get_contents( 'php://input' ), true );
		if ( ! empty( $data['repository']['name'] ) && ! empty( $data['ref'] ) ) {
			return $data;
		}

		/* Attempt to extract JSON data from payload. */
		if ( ! empty( $data['payload'] ) ) {
			$data = $data['payload'];

			/* Determine if we need to JSON-decode the payload. */
			if ( ! is_array( $data ) ) {
				$data = json_decode( $data, true );
			}

			/* Determine if data is properly formed. */
			if ( ! empty( $data['repository']['name'] ) && ! empty( $data['ref'] ) ) {
				return $data;
			}
		}

		/* Attempt to extract JSON data from $_POST. */
		if ( empty( $_POST['payload'] ) ) {
			return false;
		}

		/* Attempt to decode the payload. */
		$data = json_decode( $_POST['payload'], true );
		if ( empty( $data['repository']['name'] ) || empty( $data['ref'] ) ) {
			return false;
		}

		return $data;
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
}
