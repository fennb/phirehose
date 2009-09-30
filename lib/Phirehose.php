<?php
/**
 * Please read: http://apiwiki.twitter.com/Streaming-API-Documentation carefully before using.
 * 
 * @author  Fenn Bailey <fenn.bailey@gmail.com>
 * @version ($Id$)
 */
abstract class Phirehose
{
  
  /**
   * Class constants
   */
  const URL_BASE         = 'http://stream.twitter.com/1/statuses/';
  const FORMAT_JSON      = 'json';
  const FORMAT_XML       = 'xml';
  const METHOD_FILTER    = 'filter';
  const METHOD_SAMPLE    = 'sample';
  const METHOD_RETWEET   = 'retweet';
  const METHOD_FIREHOSE  = 'firehose';
  const USER_AGENT       = 'Phirehose/0.1.0 +http://code.google.com/p/phirehose/';
  const FILTER_CHECK_MIN = 5;
  const FILTER_UPD_MIN   = 120;
  const TCP_BACKOFF      = 1;
  const TCP_BACKOFF_MAX  = 16;
  const HTTP_BACKOFF     = 10;
  const HTTP_BACKOFF_MAX = 240;
  
  
  /**
   * Member Attribs
   */
  protected $username;
  protected $password;
  protected $method;
  protected $format;
  protected $count;
  protected $followIds;
  protected $trackWords;
  protected $conn;
  // State vars
  protected $filterChanged;
  protected $reconnect;
  protected $statusRate;
  // Config type vars - override in subclass if desired
  protected $connectFailuresMax = 20; 
  protected $connectTimeout = 5;
  protected $idleTimeout = 5;
  protected $avgPeriod = 60;

  /**
   * Create a new Phirehose object attached to the appropriate twitter stream method. 
   * Methods are: METHOD_FIREHOSE, METHOD_RETWEET, METHOD_SAMPLE, METHOD_FILTER
   * Formats are: FORMAT_JSON, FORMAT_XML
   * @see Phirehose::METHOD_SAMPLE
   * @see Phirehose::FORMAT_JSON
   * 
   * @param string $username Any twitter username
   * @param string $password Any twitter password
   * @param string $method
   * @param string $format
   */
  public function __construct($username, $password, $method = Phirehose::METHOD_SAMPLE, $format = self::FORMAT_JSON)
  {
    $this->username = $username;
    $this->password = $password;
    $this->method = $method;
    $this->format = $format;
  }
  
  /**
   * Returns public statuses from or in reply to a set of users. Mentions ("Hello @user!") and implicit replies 
   * ("@user Hello!" created without pressing the reply button) are not matched. It is up to you to find the integer
   * IDs of each twitter user.
   * Applies to: METHOD_FILTER
   * 
   * @param array $userIds Array of Twitter integer userIDs
   */
  public function setFollow($userIds)
  {
    sort($userIds); // Non-optimal but necessary 
    if ($this->followIds != NULL && $this->followIds != $userIds) {
      $this->filterChanged = TRUE;
    }
    $this->followIds = $userIds;
  }
  
  /**
   * Returns an array of followed Twitter userIds (integers)
   *
   * @return array
   */
  public function getFollow()
  {
    return $this->followIds;
  }
  
  /**
   * Specifies keywords to track. Track keywords are case-insensitive logical ORs. Terms are exact-matched, ignoring 
   * punctuation. Phrases, keywords with spaces, are not supported. Queries are subject to Track Limitations.
   * Applies to: METHOD_FILTER
   * 
   * See: http://apiwiki.twitter.com/Streaming-API-Documentation#TrackLimiting
   *
   * @param array $trackWords
   */
  public function setTrack($trackWords)
  {
    sort($trackWords); // Non-optimal, but necessary
    if ($this->trackWords != NULL && $this->trackWords != $trackWords) {
      $this->filterChanged = TRUE;
    }
    $this->trackWords = $trackWords;
  }
  
  /**
   * Returns an array of keywords being tracked 
   *
   * @return array
   */
  public function getTrack()
  {
    return $this->trackWords;
  }
  
  /**
   * Sets the number of previous statuses to stream before transitioning to the live stream. Applies only to firehose
   * and filter + track methods. This is generally used internally and should not be needed by client applications.
   * Applies to: METHOD_FILTER, METHOD_FIREHOSE
   * 
   * @param integer $count
   */
  public function setCount($count)
  {
    $this->count = $count;  
  }
  
  /**
   * Connects to the stream API and consumes the stream. Each status update in the stream will cause a call to the
   * handleStatus() method.
   * 
   * @see handleStatus()
   * @param boolean $reconnect Reconnects as per recommended   
   * @throws ErrorException
   */
  public function consume($reconnect = TRUE)
  {
    // Persist connection?
    $this->reconnect = $reconnect;
    
    // Loop indefinitely based on reconnect
    do {
      
      // (Re)connect
      $this->reconnect();
    
      // Init state
      $statusCount = $filterCheckCount = $enqueueSpent = $filterCheckSpent = 0;
      $lastAverage = $lastFilterCheck = $lastFilterUpd = time();
      $buff = '';
      $fdr = array($this->conn);
      $fdw = $fde = NULL;
      
      // We use a blocking-select with timeout, to allow us to continue processing on idle streams
      while ($this->conn !== NULL && !feof($this->conn) && ($numChanged = stream_select($fdr, $fdw, $fde, $this->idleTimeout)) !== FALSE) {
        $fdr = array($this->conn); // Must reassign for stream_select()
        $buff .= fread($this->conn, 6); // Small non-blocking to get delimiter text
        if (($eol = strpos($buff, "\r\n")) === FALSE) {
          continue; // We need a newline
        }
        // Read status length delimiter
        $delimiter = substr($buff, 0, $eol);
        $buff = substr($buff, $eol + 2); // consume off buffer, + 2 = "\r\n"
        $statusLength = intval($delimiter);
        if ($statusLength > 0) {
          // Read status bytes and enqueue
          $bytesLeft = $statusLength - strlen($buff);
          while ($bytesLeft > 0 && $this->conn !== NULL && !feof($this->conn) && ($numChanged = stream_select($fdr, $fdw, $fde, 0, 20000)) !== FALSE) {
            $fdr = array($this->conn); // Reassign
            $buff .= fread($this->conn, $bytesLeft); // Read until all bytes are read into buffer
            $bytesLeft = ($statusLength - strlen($buff));
          }
          // Accrue/enqueue and track time spent enqueing
          $statusCount ++;
          $enqueueStart = microtime(TRUE);
          $this->enqueueStatus($buff);
          $enqueueSpent += (microtime(TRUE) - $enqueueStart);
        } else {
          // Timeout/no data after idleTimeout seconds
          
        }
        // Calc counter averages 
        $avgElapsed = time() - $lastAverage;
        if ($avgElapsed >= $this->avgPeriod) {
          // Calc tweets-per-second
          $this->statusRate = round($statusCount / $avgElapsed, 0);
          // Calc time spent per enqueue in ms
          $enqueueTimeMS = ($statusCount > 0) ? round($enqueueSpent / $statusCount * 1000, 2) : 0;
          // Cal time spent total in filter predicate checking
          $filterCheckTimeMS = ($filterCheckCount > 0) ? round($filterCheckSpent / $filterCheckCount * 1000, 2) : 0;
          $this->log('Phirehose rate: ' . $this->statusRate . ' status/sec (' . $statusCount . ' total), avg ' . 
            'enqueueStatus(): ' . $enqueueTimeMS . 'ms, avg checkFilterPredicates(): ' . $filterCheckTimeMS . 'ms (' . 
            $filterCheckCount . ' total) over ' . $this->avgPeriod . ' seconds.');
          // Reset
          $statusCount = $filterCheckCount = $enqueueSpent = $filterCheckSpent = 0;
          $lastAverage = time();
        }
        // Check if we're ready to check filter predicates
        if ($this->method == self::METHOD_FILTER && (time() - $lastFilterCheck) >= self::FILTER_CHECK_MIN) {
          $filterCheckCount ++;
          $lastFilterCheck = time();
          $filterCheckStart = microtime(TRUE);
          $this->checkFilterPredicates(); // This should be implemented in subclass if required
          $filterCheckSpent +=  (microtime(TRUE) - $filterCheckStart);
        }
        // Check if filter is ready + allowed to be updated (reconnect)
        if ($this->filterChanged == TRUE && (time() - $lastFilterUpd) >= self::FILTER_UPD_MIN) {
          $this->log('Phirehose: Updating filter predicates (reconnecting).');
          $lastFilterUpd = time();
          $this->reconnect();
          $fdr = array($this->conn); // Ugly, but required
        }
        
      } // End while-stream-activity

      // Some sort of socket error has occured
      $error = is_resource($this->conn) ? @socket_last_error($this->conn) : 'Socket disconnected';
      $this->log('Phirehose connection error occured: ' . $error);
      
      // Reconnect 
    } while ($this->reconnect);

    // Exit
    $this->log('Phirehose exiting');
    
  }
  
  /**
   * Connects to the stream URL using the configured method.
   * @throws ErrorException
   */
  protected function connect() 
  {
    // Check filter predicates pre-connect (for filter method)
    if ($this->method == self::METHOD_FILTER) {
      $this->checkFilterPredicates();
    }
    
    // Construct URL/HTTP bits
    $url = self::URL_BASE . $this->method . '.' . $this->format;
    $urlParts = parse_url($url);
    $authCredentials = base64_encode($this->username . ':' . $this->password);
    
    // Setup params appropriately
    $requestParams = array('delimited' => 'length');
    
    // Filter takes additional parameters
    if ($this->method == self::METHOD_FILTER && count($this->trackWords) > 0) {
      $this->trackWords;
      $requestParams['track'] = implode(',', $this->trackWords);
    }
    if ($this->method == self::METHOD_FILTER && count($this->followIds) > 0) {
      $this->followIds;
      $requestParams['follow'] = implode(',', $this->followIds); 
    }
    if ($this->count > 0) {
      $requestParams['count'] = $this->count;    
    }

    // Keep trying until connected (or max connect failures exceeded)
    $connectFailures = 0;
    $tcpRetry = self::TCP_BACKOFF / 2;
    $httpRetry = self::HTTP_BACKOFF / 2;
    do {

      // Debugging is useful
      $this->log('Phirehose: Connecting to stream: ' . $url . ' with params: ' . str_replace("\n", '', 
        var_export($requestParams, TRUE)));
      
      /**
       * Open socket connection to make POST request. It'd be nice to use stream_context_create with the native 
       * HTTP transport but it hides/abstracts too many required bits (like HTTP error responses).
       */
      $errNo = $errStr = NULL;
      $scheme = ($urlParts['scheme'] == 'https') ? 'ssl://' : 'tcp://';
      @$this->conn = fsockopen($scheme . $urlParts['host'], 80, $errNo, $errStr, $this->connectTimeout);
  
      // No go - handle errors/backoff
      if (!$this->conn) {
        $connectFailures ++;
        if ($connectFailures > $this->connectFailuresMax) {
          $msg = 'Connection failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
          $this->log('Phirehose: ' . $msg);
          throw new ErrorException($msg); // We eventually throw an exception for other code to handle          
        }
        // Increase retry/backoff up to max
        $tcpRetry = ($tcpRetry < self::TCP_BACKOFF_MAX) ? $tcpRetry * 2 : self::TCP_BACKOFF_MAX;
        $this->log('Phirehose: Failed to connect to stream: ' . $errStr . ' (' . $errNo . '). Sleeping for ' . $tcpRetry . ' seconds.');
        sleep($tcpRetry);
        continue;
      }
      
      // If we have a socket connection, we can attempt a HTTP request - Ensure blocking read for the moment
      stream_set_blocking($this->conn, 1);
  
      // Encode request data
      $postData = http_build_query($requestParams);
      
      // Do it
      fwrite($this->conn, "POST " . $urlParts['path'] . " HTTP/1.0\r\n");
      fwrite($this->conn, "Host: " . $urlParts['host'] . "\r\n");
      fwrite($this->conn, "Content-type: application/x-www-form-urlencoded\r\n");
      fwrite($this->conn, "Content-length: " . strlen($postData) . "\r\n");
      fwrite($this->conn, "Accept: */*\r\n");
      fwrite($this->conn, 'Authorization: Basic ' . $authCredentials . "\r\n");
      fwrite($this->conn, 'User-Agent: ' . self::USER_AGENT . "\r\n");
      fwrite($this->conn, "\r\n");
      fwrite($this->conn, $postData . "\r\n");
      fwrite($this->conn, "\r\n");
      
      // First line is response
      list($httpVer, $httpCode, $httpMessage) = split(' ', trim(fgets($this->conn, 1024)), 3);
      
      // Response buffers
      $respHeaders = $respBody = '';

      // Consume each header response line until we get to body
      while ($hLine = trim(fgets($this->conn, 4096))) {
        $respHeaders .= $hLine;
      }
      
      // If we got a non-200 response, we need to backoff and retry
      if ($httpCode != 200) {
        $connectFailures ++;
        
        // Twitter will disconnect on error, but we want to consume the rest of the response body (which is useful)
        while ($bLine = trim(fgets($this->conn, 4096))) {
          $respBody .= $bLine;
        }
        
        // Construct error
        $errStr = 'HTTP ERROR ' . $httpCode . ': ' . $httpMessage . ' (' . $respBody . ')'; 
        
        // Have we exceeded maximum failures?
        if ($connectFailures > $this->connectFailuresMax) {
          $msg = 'Connection failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
          $this->log('Phirehose: ' . $msg);
          throw new ErrorException($msg); // We eventually throw an exception for other code to handle          
        }
        // Increase retry/backoff up to max
        $httpRetry = ($httpRetry < self::HTTP_BACKOFF_MAX) ? $httpRetry * 2 : self::HTTP_BACKOFF_MAX;
        $this->log('Phirehose: Failed to connect to stream: ' . $errStr . '. Sleeping for ' . $httpRetry . ' seconds.');
        sleep($httpRetry);
        continue;
        
      } // End if not http 200
      
      // Loop until connected OK
    } while (!is_resource($this->conn) || $httpCode != 200);
    
    // Switch to non-blocking to consume the stream (important) 
    stream_set_blocking($this->conn, 0);
    
    // Connect always causes the filterChanged status to be cleared
    $this->filterChanged = FALSE;
    
  }
  
  /**
   * Method called as frequently as practical (every 5+ seconds) that is responsible for checking if filter predicates
   * (ie: track words or follow IDs) have changed. If they have, they should be set using the setTrack() and setFollow()
   * methods respectively within the overridden implementation. 
   * 
   * Note that even if predicates are changed every 5 seconds, an actual reconnect will not happen more frequently than
   * every 2 minutes (as per Twitter Streaming API documentation)
   * 
   * This should be implemented/overridden in any subclass implementing the FILTER method.
   *
   * @see setTrack()
   * @see setFollow()
   * @see Phirehose::METHOD_FILTER
   */
  protected function checkFilterPredicates()
  {
    // Override in subclass
  }
  
  /**
   * Basic log function that outputs logging to the standard error_log() handler. This should generally be overridden
   * to suit the application environment.
   *
   * @see error_log()
   * @param string $messages
   */
  protected function log($message)
  {
    @error_log($message, 0);
  }

  /**
   * Performs forcible disconnect from stream (if connected) and cleanup
   */
  private function disconnect()
  {
    if (is_resource($this->conn)) {
      $this->log('Phirehose: Closing connection.');
      fclose($this->conn);
    }
    $this->conn = NULL;
    $this->reconnect = FALSE;
  }
  
  /**
   * Reconnects as quickly as possible. Should be called whenever a reconnect is required rather that connect/disconnect
   * to preserve streams reconnect state
   */
  private function reconnect()
  {
    $reconnect = $this->reconnect;
    $this->disconnect(); // Sets reconnect to FALSE
    $this->connect(); 
    $this->reconnect = $reconnect; // Restore state
  }
  
  /**
   * This is the one and only method that must be implemented additionally. As per the streaming API documentation,
   * statuses should NOT be processed within the same process that is performing collection 
   *
   * @param string $status
   */
  abstract public function enqueueStatus($status);
  
} // End of class