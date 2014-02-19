<?php

/**
 * An error handler class with the following features:
 * - Able to handle uncaught errors, such fatals and memory errors.
 * - Handles each error only once, to prevent flooding of for instance a notice
 *   within a loop.
 * - Only handles errors specfied by error_reporting().
 * - Increases the memory limit before handling an error. This prevents a memory
 *   error within the error handler.
 * - Takes errors, fatals and exceptions within each handler function into
 *   account. Only fatal errors within fatal error handling are impossible to
 *   handle.
 */

namespace ultimo\debug\error;

require_once(dirname(__FILE__) . '/ErrorHandler.php');

class ErrorPrinter extends ErrorHandler {
  
  /**
   * Returns the required amount memory for the handling of the error in
   * megabytes. When a memory error occurs, there could be not enough memory
   * to handle the error, so the limit is increased with this amount.
   * @return int The number of megabytes to increase the memory on an error.
   */
  protected function getMemoryRequirement() {
    return 1;
  }
  
  /**
   * Handle a PHP error.
   * @param ErrorException $e The error to be handled.
   */
  public function handleError(\ErrorException $e) {
    $this->printException($e);
  }

  /**
   * Handle an exception.
   * @param Exception $e The exception to be handled.
   */
  public function handleException(\Exception $e) {
    $this->printException($e);
  }

  /**
   * Handles an uncaught exception thrown within this error handler. So make
   * sure this function handles the exception on a different, light-weight
   * fasion, to prevent the exception from occuring again. An exception in
   * this function is not handled, and will cause php to end.
   */
  protected function handleInternalException(\Exception $e) {
    $this->printException($e);
  }
  
  protected function printException(\Exception $e) {
    echo '<pre>';
    echo $e;
    echo '</pre>';
  }
  
}