<?php

namespace ultimo\debug\error;

require_once(dirname(__FILE__) . '/ErrorHandler.php');

class ErrorCarer extends ErrorHandler {
  
  protected $defaultConfig = array(
    'print_errors' => true,
    'email_to' => array(),
    'email_from' => 'ErrorCarer <errorcarer@ultimo.com>',
    'email_subject' => '[Errorcarer] %s: \'%s\' (code %s) in %s:%s',
    'response' => 'An error has occured.'
  );
  
  protected $config = array();
  
  public function __construct(array $config) {
    $this->config = $this->mergeAttrs($this->defaultConfig, $config);
    
    if (is_string($this->config['email_to'])) {
      $this->config['email_to'] = array($this->config['email_to']);
    }
  }
  
   /**
   * Merges two multi dimensional attribute arrays
   * @param array $attrs1 The attributes to merge into.
   * @param array $attrs2 The attributes to merge with.
   * @return array The merged attributes.
   */
  protected function mergeAttrs($attrs1, $attrs2) {
    foreach ($attrs2 as $key => &$value) {
      if (is_array($value) && array_key_exists($key, $attrs1) && is_array($attrs1[$key])) {
        $attrs1[$key] = $this->mergeAttrs($attrs1[$key], $value);
      } else {
        $attrs1[$key] = $value;
      }
    }
    
    return $attrs1;
  }
  
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
    $this->_handleException($e);
  }

  /**
   * Handle an exception.
   * @param Exception $e The exception to be handled.
   */
  public function handleException(\Exception $e) {
    $this->_handleException($e);
  }

  /**
   * Handles an uncaught exception thrown within this error handler. So make
   * sure this function handles the exception on a different, light-weight
   * fasion, to prevent the exception from occuring again. An exception in
   * this function is not handled, and will cause php to end.
   */
  protected function handleInternalException(\Exception $e) {
    $this->_handleException($e);
  }
  
  public function handleCaughtException(\Exception $e) {
    if (!empty($this->config['email_to'])) {
      $message = $this->getType($e) . ': <pre>' . $e . '</pre>';
      
      $message .= '<br />Request: <pre>' . print_r($_REQUEST, true) . '</pre>';
      
      $message .= '<br />Server variables: <pre>' . print_r($_SERVER, true) . '</pre>';

      $debug = array(
          'getcwd()' => getcwd()
      );
      $message .= '<br />Various debug info: <pre>' . print_r($debug, true) . '</pre>';
      
      $to = implode(',', $this->config['email_to']);
      $subject = sprintf($this->config['email_subject'], $this->getType($e), $e->getMessage(), $e->getCode(), $e->getFile(), $e->getLine());
     
      
      $headers  = 'MIME-Version: 1.0' . "\r\n";
      $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
      $headers .= 'From: ' . $this->config['email_from'] . "\r\n";
      
//      echo '<br /><br />';
//      echo '<hr>';
//      echo "To: " . $to . '<br />';
//      echo "Subject: " . $subject . '<br />';
//      echo $message . '<br /><br />';
//      echo '<hr>';
      $result = mail($to, $subject, $message, $headers);
    }
  }
  
  protected function _handleException(\Exception $e) {
    if ($this->config['print_errors']) {
      echo '<pre>';
      echo $e;
      echo '</pre>';
    }
    
    $exitOnEnd = false;
    
    if ($e instanceof \ErrorException) {
      /* @var \ErrorException $e */
      if ($e->getCode() & (E_ERROR | E_USER_ERROR)) {
        if (is_readable($this->config['response'])) {
          echo file_get_contents($this->config['response']);
        } else {
          echo $this->config['response'];
        }
        
        $exitOnEnd = true;
      }
    }
    
    $this->handleCaughtException($e);
    if ($exitOnEnd) {
      exit();
    }
  }
  
}