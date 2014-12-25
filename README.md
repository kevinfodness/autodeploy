Autodeploy
==========

Continuous Integration (CI) for webhooks from various providers.

Requirements:

* PHP 5.4
* PHP MCrypt module
* PHP HTTP module
* PHP shell_exec enabled
* git installed on the server
* Apache process able to access shell and pull/push changes to git remote

Currently supports:

* [Beanstalk Classic Webhooks](http://support.beanstalkapp.com/customer/portal/articles/75753-trigger-a-url-on-commit-with-web-hooks)
* [GitHub Webhooks](https://developer.github.com/webhooks](https://developer.github.com/webhooks)

Roadmap:

* Add support for BitBucket
* Add support for routing requests to other servers in a network
* Add support for GitHub webhook secret field
* Restrict deployment requests to valid origin domains
