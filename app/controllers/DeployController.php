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
class DeployController extends BaseController
{
    /**
     * A function to receive incoming deployment requests from VCS and process them.
     *
     * @access public
     * @return void
     */
    public function deploy()
    {
        /* Attempt to process known webhook formats. */
        if ($this->_process_beanstalk_classic()
            || $this->_process_github_json()
        ) {
            return;
        }

        /* Get the contents of POST to print to the log. */
        ob_start();
        var_dump($_POST);
        var_dump(http_get_request_body());
        $content = ob_get_contents();
        ob_end_clean();

        /* Write the error including the contents of $_POST. */
        Log::error('No payload:' . "\n" . $content);
    }

    /**
     * A function to process a Beanstalk classic webhook.
     *
     * @see    http://blog.beanstalkapp.com/post/7845485728/webhooks-let-you-use-commit-messages-to-trigger-custom
     *
     * @access private
     * @return bool True on success, false on failure.
     */
    private function _process_beanstalk_classic()
    {
        /* Determine if payload is empty. */
        if (empty($_POST['payload'])) {
            return false;
        }

        /* Attempt to decode the payload. */
        $data = json_decode($_POST['payload'], true);
        if (empty($data)) {
            Log::error('Failed to get json.');

            return false;
        }

        /* Ensure that the required fields are part of the decoded JSON. */
        if (empty($data['repository']['name']) || !empty($data['branch'])) {
            return false;
        }

        /* Attempt to process the deployment. */
        return $this->_process_deployment($data['repository']['name'], $data['branch']);
    }

    /**
     * A function to process the deployment.
     *
     * @param string $repository The repository name to use when deploying.
     * @param string $branch The branch to deploy.
     *
     * @access private
     * @return bool True on success, false on failure.
     */
    private function _process_deployment($repository, $branch)
    {
        /* Determine if repository exists on this server. */
        if (!is_dir('/var/www/' . $repository)) {
            Log::info('Repository ' . $repository . ' does not exist on this system.');
            return false;
        }

        /* TODO: Determine if current branch checked out for repository matches requested branch. */

        /* TODO: Route to other servers in the network if repository not found on this system. */

        Log::info('Starting deployment for ' . $repository . ' on branch ' . $branch);

        /* Change working directory to repository directory. */
        chdir('/var/www/' . $repository);

        /* Get information about this repository. */
        $push = false;
        $status = shell_exec('git status');

        /* Determine if we need to stage files for commit. */
        if (stristr($status, 'Untracked files:') !== false || stristr($status, 'Changes not staged for commit:') !== false) {
            Log::info('Adding untracked working tree files.');
            shell_exec(escapeshellcmd('git add -A'));

            /* Refresh status for next step. */
            $status = shell_exec(escapeshellcmd('git status'));
        }

        /* Determine if we need to do a commit. */
        if (stristr($status, 'Changes to be committed:') !== false) {
            $push = true;
            Log::info('Committing modified files and flagging for push.');
            shell_exec(escapeshellcmd('git commit -am \'Refreshing branch with updated files.\''));
        }

        /* Pull changes from remote. */
        Log::info('Pulling remote changes.');
        shell_exec(escapeshellcmd('git pull origin ' . $branch));

        /* Push changes to remote, if necessary. */
        if ($push === true) {
            Log::info('Synchronizing modified local files with remote.');
            shell_exec(escapeshellcmd('git push origin ' . $branch));
        }

        Log::info('Finished deployment for ' . $repository . ' on branch ' . $branch);

        return true;
    }

    /**
     * A function to try to process a GitHub webhook in application/json format.
     *
     * @access private
     * @return bool True on success, false on failure.
     */
    private function _process_github_json() {
        return false;
    }
}