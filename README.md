# Ultimo - Error
Extensive error and exception catcher

Catches all exceptions and almost all errors. The main class is the result of years of experience with several high-traffic websites.

## Features
* Able to handle uncaught errors, such fatals and memory errors.
* Handles each error only once, to prevent flooding of for instance a notice within a loop.
* Only handles errors specfied by error_reporting().
* Increases the memory limit before handling an error. This prevents a memory error within the error handler.
* Takes errors, fatals and exceptions within each handler function into account. Only fatal errors within fatal error handling are impossible to handle.
* Has the same abilities to log and ignore repeated errors as the default error handling. Uses the php ini settings for this, but these may be overridden.
* Two (sample) handlers are provided:
 * ErrorPrinter: Just prints errors
 * ErrorCarer: Mails errors

## Requirements
* PHP 5.3

## Usage

###
Register an ErrorHandler first, a anything may trigger errors or exceptions. You have to trust the ErrorHandler itself not to trigger errors though.
	
	<?php
	// require the ErrorHandler manually, as an autoloading mechanism may trigger errors
    require_once('ultimo/debug/error/ErrorPrinter.php');

	// register errorHandler (for fatals)
    $errorHandler = new \ultimo\debug\error\ErrorPrinter();
	
	// ... Start your script or application
    doYourThing();

    // unregister errorHandler (to indicate there was no fatal)
    $errorHandler->unregister();

	// End of script or application