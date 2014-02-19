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
 * - Has the same abilities to log and ignore repeated errors as the default
 *   error handling. Uses the php ini settings for this, but these may be
 *   overridden.
 */
namespace ultimo\debug\error;


abstract class ErrorHandler {

    /**
     * Whether this class is registered as error handler. If false, errors
     * will ne ignored.
     * @var boolean
     */
    private $isRegistered = false;
    
    /**
     * Tells whether script error messages should be logged to the server's
     * error log or error_log. This option is thus server-specific. If set to
     * null, the php ini value 'log_errors' will be used.
     * @var boolean 
     */
    protected $logErrors = null;

    /**
     * Do not log repeated messages. Repeated errors must occur in the same file
     * on the same line unless ignoreRepeatedSource is set true. If set to null,
     * the php ini value 'ignore_repeated_errors' will be used.
     * @var boolean 
     */
    protected $ignoreRepeatedErrors = null;
    
    /**
     * Ignore source of message when ignoring repeated messages. When this
     * setting is on you will not log errors with repeated messages from
     * different files or sourcelines. If set to null, the php ini value
     * 'ignore_repeated_source' will be used.
     * @var boolean 
     */
    protected $ignoreRepeatedSource = null;
    
    /**
     * An array with md5 hashes of errors as keys to keep track of which errors
     * are already encountered and handled.
     * @var array
     */
    protected $handledErrorHashes = array();
    
    /**
     * The last encoutered error.
     * @var \ErrorException 
     */
    protected $lastError;
    
    /**
     * The error constants. A hashtable with error codes as key and error names
     * as value
     * @var hash 
     */
    static protected $errorNames = array(
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core error',
        E_CORE_WARNING => 'Core warning',
        E_COMPILE_ERROR => 'Compile error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User error',
        E_USER_WARNING => 'User warning',
        E_USER_NOTICE => 'User notice',
        E_RECOVERABLE_ERROR => 'Recoverable error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User deprecated'
    );
    
    /**
     * Registers this class as handler for errors, fatals and exceptions.
     */
    public function register() {
        set_error_handler(array($this, '_handleErrorCallback'));
        set_exception_handler(array($this, '_handleExceptionCallback'));
        register_shutdown_function(array($this, '_checkForUncaughtErrorCallback'));
        $this->isRegistered = true;
    }

    /**
     * unregisters this class as handler for errors, fatals and exceptions.
     */
    public function unregister() {
        $this->isRegistered = false;
        restore_error_handler();
        restore_exception_handler();
    }

    /**
     * Returns whether this class is registered as error handler.
     * @return boolean Whether this class is registered as error handler.
     */
    public function isRegistered() {
        return $this->isRegistered;
    }

    /**
     * Returns the name for an error code.
     * @param int $errorCode Error code.
     * @return string Name of the error code.
     */
    static public function getErrorName($errorCode) {
        if (isset(self::$errorNames[$errorCode])) {
            return self::$errorNames[$errorCode];
        } else {
            return 'Unknown error(' . $errorCode . ')';
        }
    }
    
    /**
     * Returns the hash of an error exception.
     * @param ErrorException $e The error exception to hash.
     * @param boolean Whether to ignore the source while hashing.
     * @return string The hash of the error exception.
     */
    protected function hashError(\ErrorException $e, $ignoreSource=false) {
        if ($ignoreSource) {
            return md5($e->getCode() . ';' . $e->getMessage());
        } else {
            return md5($e->getCode() . ';' . $e->getMessage() . ';' . $e->getFile() . ';' . $e->getLine());
        }
    }
    
    /**
     * Returns whether ignore repeating errors is enabled and if so, whether
     * the specified ErrorException is a repeating one.
     * @param \ErrorException $e The error to check whether it is a repeated
     * error.
     * @return boolean Either ignore repeating errors is enabled and if so,
     * whether the specified ErrorException is a repeating one.
     */
    protected function isRepeatedError(\ErrorException $e) {
        // use member value if not null, else use ini value
        $ignoreRepeatedErrors = $this->ignoreRepeatedErrors;
        if ($ignoreRepeatedErrors === null) {
            $ignoreRepeatedErrors = ini_get('ignore_repeated_errors');
        }
        
        if (!$ignoreRepeatedErrors) {
            return false;
        }
        
        // use member value if not null, else use ini value
        $ignoreRepeatedSource = $this->ignoreRepeatedSource;
        if ($ignoreRepeatedSource === null) {
            $ignoreRepeatedSource = ini_get('ignore_repeated_source');
        }
        
        $hash = $this->hashError($e, $ignoreRepeatedSource);
        
        return isset($this->handledErrorHashes[$hash]);
    }
    
    /**
     * Stores an error's hash to preveting handling repeating errors.
     * @param \ErrorException $e Error to store.
     */
    protected function storeErrorHash(\ErrorException $e) {
        // store both the hashes with and without source.
        $this->handledErrorHashes[$this->hashError($e, false)] = true;
        $this->handledErrorHashes[$this->hashError($e, true)] = true;
    }

    /**
     * Called by PHP when an error occurs.
     */
    public function _handleErrorCallback($errno, $errstr, $errfile, $errline) {
        // Errors in this function become fatal errors, and will be handled by _checkForUncaughtErrorCallback()
        // Fatal errors in this function will be handled by checkForUncaughtErrorCallback()
        // Exception in this function will be caught by the try-catch statement
        
        if (!$this->isRegistered) {
            return false;
        }

        // increase memory limit to prevent memory errors during handling
        $currentLimit = $this->increaseMemoryLimit();

        $e = new \ErrorException($errstr, $errno, 0, $errfile, $errline);
        $this->lastError = $e;
        
        // only handle errors specified by error_reporting()
        if ((error_reporting() & $errno) == 0) {
            $this->restoreMemoryLimit($currentLimit);
            
            // prevent this error being processed in _checkForUncaughtErrorCallback
            $this->storeErrorHash($e);
            return false;
        }

        // don't handle already handled errors.
        if ($this->isRepeatedError($e)) {
            $this->restoreMemoryLimit($currentLimit);
            return;
        }

        try {
            $this->log($e);
            $this->handleError($e);
        } catch (Exception $e2) {
            $this->log($e2);
            $this->handleInternalException($e2);
        }

        $this->storeErrorHash($e);
        $this->restoreMemoryLimit($currentLimit);
    }

    /**
     * Called by PHP when an exception is uncaught.
     */
    public function _handleExceptionCallback(\Exception $e) {
        // Errors in this function will be handled by _handleErrorCallback()
        // Fatal errors in this function will be handled by checkForUncaughtErrorCallback()
        // Exception in this function will be caught by the try-catch statement

        if (!$this->isRegistered) {
            return false;
        }

        // increase memory limit to prevent memory errors during handling
        $currentLimit = $this->increaseMemoryLimit();

        try {
            $this->log($e);
            $this->handleException($e);
        } catch (Exception $e2) {
            $this->log($e2);
            $this->handleInternalException($e2);
        }
        $this->restoreMemoryLimit($currentLimit);
    }
    
    /**
     * Called by PHP on shutdown to check if there are unhandled errors.
     */
    public function _checkForUncaughtErrorCallback() {
        // Errors in this function are handled by _handleErrorCallback()
        // Fatal errors in this function are impossible to handle!
        // Exception in this function will be caught by the try-catch statement

        if (!$this->isRegistered) {
            return;
        }

        $error = error_get_last();
        if ($error) {

            // increase memory limit to prevent memory errors during handling
            $currentLimit = $this->increaseMemoryLimit();

            // only handle errors specified by error_reporting()
            if (!(error_reporting() & $error['type'])) {
                return;
            }

            $e = new \ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']);

            // don't handle the last error, if it was the last error handled
            if ($this->lastError !== null && $this->hashError($this->lastError) == $this->hashError($e)) {
                $this->restoreMemoryLimit($currentLimit);
                return;
            }
            

            try {
                $this->log($e);
                $this->handleError($e);
            } catch (Exception $e2) {
                $this->log($e2);
                $this->handleInternalException($e2);
            }

            $this->storeErrorHash($e);
            
            $this->restoreMemoryLimit($currentLimit);
        }
    }


    /**
     * Checks whether logging is required and calls the proper logging function.
     * @param \Exception $e The exception to log.
     */
    protected function log(\Exception $e) {
        // use member value if not null, else use ini value
        $logErrors = $this->logErrors;
        if ($logErrors === null) {
            $logErrors = ini_get('log_errors');
        }
        
        if (!$logErrors) {
            return;
        }
      
        if ($e instanceof \ErrorException) {
            $this->logError($e);
        } else {
            $this->logException($e);
        }
    }
    
    /**
     * Logs a PHP error. Mimics php default behaviour. Override this for custom
     * logging.
     * @param \ErrorException $e The error to log.
     */
    public function logError(\ErrorException $e) {
        $errorName = self::getErrorName($e->getCode());
        error_log("PHP {$errorName}:  {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
    }
    
    /**
     * Logs an exception. Mimics php default behaviour. Override this for custom
     * logging.
     * @param \Exception $e The exception to log.
     */
    public function logException(\Exception $e) {
        $exceptions = array();
        while ($e !== null) {
            array_unshift($exceptions, $e);
            $e = $e->getPrevious();
        }
        
        $e = array_shift($exceptions);
        
        // create the message for the first exception
        $class = get_class($e);
        $message  = "PHP Fatal error:  Uncaught exception '{$class}' with message '{$e->getMessage()}' in {$e->getFile()}:{$e->getLine()}\n";
        $message .= "Stack Trace:\n{$e->getTraceAsString()}";

        // create the message for the next exceptions
        foreach ($exceptions as $e) {
            $class = get_class($e);
            $message .= "\n\nNext exception '{$class}' with message '{$e->getMessage()}' in {$e->getFile()}:{$e->getLine()}\n";
            $message .= "Stack Trace:\n{$e->getTraceAsString()}";
        }
        
        // append last line
        $message .= "\n  thrown in {$e->getFile()} on line {$e->getLine()}";
        
        error_log($message);
    }
    
    /**
     * Handle a PHP error.
     * @param \ErrorException $e The error to be handled.
     */
    abstract public function handleError(\ErrorException $e);

    /**
     * Handle an exception.
     * @param \Exception $e The exception to be handled.
     */
    abstract public function handleException(\Exception $e);

    public function handleCaughtException(\Exception $e) {
      $this->handleException($e);
    }

    
    /**
     * Handles an uncaught exception thrown within this error handler. So make
     * sure this function handles the exception on a different, light-weight
     * fasion, to prevent the exception from occuring again. An exception in
     * this function is not handled, and will cause php to end.
     */
    abstract protected function handleInternalException(\Exception $e);

    /**
     * Returns the required amount memory for the handling of the error in
     * megabytes. When a memory error occurs, there could be not enough memory
     * to handle the error, so the limit is increased with this amount.
     * @return int The number of megabytes to increase the memory on an error.
     */
    abstract protected function getMemoryRequirement();

    /**
     * Increases the memory limit according to the memory requirement for the
     * handling.
     * @return string The previous memory limit.
     */
    protected function increaseMemoryLimit() {
        // i think php increases the memory limit a little bit, as the following
        // works, even if the uncaught error is a memory error about 1 byte not
        // being allocated.
        $currentLimit = trim(ini_get('memory_limit'));
        $unit = strtolower(substr($currentLimit, - 1));
        $factor = 1;

        // memory limit is a string like 1G, 24M, 7000k or 182981723.
        switch ($unit) {
            case 'g':
                $factor = 1024 * 1024 * 1024;
                break;
            case 'm':
                $factor = 1024 * 1024;
                break;
            case 'k':
                $factor = 1024;
                break;
            default:
                $factor = 1;
        }

        ini_set('memory_limit', ($currentLimit * $factor) + ($this->getMemoryRequirement() * 1024 * 1024));
        return $currentLimit;
    }

    /**
     * Restores the memory limit to its previous value.
     * @param string $limit The previous memory limit.
     */
    protected function restoreMemoryLimit($limit) {
        ini_set('memory_limit', $limit);
    }
    
    /**
     * Returns the type of an exception.
     * @param \Exception $e Exception to get the type of.
     * @return string Type of the specified exception.
     */
    protected function getType(\Exception $e) {
      if ($e instanceof \ErrorException) {
        return $this->getErrorName($e->getCode());
      } else {
        return get_class($e);
      }
    }
    
    /** 
     * Sets whether script error messages should be logged. If set to null, the
     * php ini value 'log_errors' will be used.
     * @param boolean $flag Whether script error messages should be logged.
     */
    public function setLogErrors($flag) {
        $this->logErrors = $flag;
    }
    
    /** 
     * Returns whether script error messages should be logged. If null, the php
     * ini value 'log_errors' will be used.
     * @return boolean Whether script error messages should be logged.
     */
    public function getLogErrors() {
        return $this->logErrors;
    }
    
    /**
     * Sets whether to ignore repeated errors. If set to null, the php ini value
     * 'ignore_repeated_errors' will be used.
     * @param boolean $flag Whether to ignore repeated errors.
     */
    public function setIgnoreRepeatedErrors($flag) {
        $this->ignoreRepeatedErrors = $flag;
    }
    
    /**
     * Returns whether to ignore repeated errors. If null, the php ini value
     * 'ignore_repeated_errors' will be used.
     * @return boolean Whether to ignore repeated errors.
     */
    public function getIgnoreRepeatedErrors() {
        return $this->ignoreRepeatedErrors;
    }
    
    /**
     * Sets whether to ignore source of message when ignoring repeated messages.
     * If set to null, the php ini value 'ignore_repeated_source' will be used.
     * @param boolean $flag Whether to ignore source of message when ignoring
     * repeated messages.
     */
    public function setIgnoreRepeatedSource($flag) {
        $this->ignoreRepeatedSource = $flag;
    }
    
    /**
     * Returns whether to ignore source of message when ignoring repeated
     * messages. If null, the php ini value 'ignore_repeated_source' will be
     * used.
     * @return boolean Whether to ignore source of message when ignoring
     * repeated messages.
     */
    public function getIgnoreRepeatedSource() {
        return $this->ignoreRepeatedSource;
    }
}