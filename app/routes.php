<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

/* Base route for the homepage. Tell the user there is nothing here. */
Route::get( '/', 'HomeController@showWelcome' );

/* Route for the post-commit hook from the VCS. */
Route::post( 'deploy', 'DeployController@deploy' );

/* Route for handling GET requests to the deploy endpoint. */
Route::get( 'deploy', 'HomeController@showWelcome' );