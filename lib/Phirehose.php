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
  const USER_AGENT       = 'Phirehose/0.1 +http://code.google.com/p/phirehose/';
  const FILTER_UPD_LIMIT = 120;
  
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
  protected $filterChanged;
  // State vars
  protected $statusRate;
  // Config type vars - override in subclass if desired
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
  public function setCount($count) {
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
  public function consume($reconnect = true) {
    $this->disconnect();
    $this->connect();
    
    // Init
    $r = array($this->conn);
    $w = NULL;
    $e = NULL;
    $statusCount = 0;
    $lastAverage = time();
    $lastFilterUpd = time();
    
    // We use a blocking-select with timeout, to allow us to continue processing on idle streams
    while (($numChanged = stream_select($r, $w, $e, $this->idleTimeout)) !== false && $this->conn !== NULL) {
      $statusLength = intval(fgets($this->conn, 6)); // Read length delimiter
      if ($statusLength > 0) {
        // Read status bytes and enqueue
        $buff = '';
        while ($bytesLeft = ($statusLength - strlen($buff)) && !feof($this->conn)) {
          $buff .= fread($this->conn, $bytesLeft);
        }
        // Accrue/enqueue and track time spent enqueing
        $statusCount ++;
        $enqueueStart = microtime(TRUE);
        $this->enqueueStatus($buff);
        $enqueueElapsed = microtime(TRUE) - $enqueueStart;
      } else {
        // Timeout/no data
        
      }
      // Calc counter averages 
      $avgElapsed = time() - $lastAverage;
      if ($avgElapsed >= $this->avgPeriod) {
        // Calc tweets-per-second
        $this->statusRate = round($statusCount / $avgElapsed, 0);
        // Reset
        $statusCount = 0;
        $lastAverage = time();
        // Log it
        $this->log('Phirehose rate: ' . $this->statusRate . ' status/sec over ' . $this->avgPeriod . ' seconds');
      }
      // Check if filter is ready + allowed to be updated (reconnect)
      if ($this->filterChanged == TRUE && (time() - $lastFilterUpd) >= self::FILTER_UPD_LIMIT) {
        $this->log('Phirehose: Updating filter predicates.');
        $this->disconnect();
        $this->connect();
      }
      
    } // End while-stream-activity
    
    // Socket error has occured
    $error = socket_last_error($this->conn);    
    $this->log('Phirehose error occured: ' . $error);
    die("Error occured: " . $error . "\n");
    
  }
  
  /**
   * Connects to the stream URL using the configured method.
   * @throws ErrorException
   */
  protected function connect() {
    // Construct various HTTP components
    $url = self::URL_BASE . $this->method . '.' . $this->format . '?delimited=length';
    $authCredentials = base64_encode($this->username . ':' . $this->password);
    $headers = array(
      'User-Agent: ' . self::USER_AGENT,
      'Authorization: Basic ' . $authCredentials
    );
    
    
    // Setup params appropriately
    $requestParams = array();
    
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
    
    // Prep/encode
    $postData = array();
    foreach ($requestParams as $pKey => $pVal) {
      $postData[] = urlencode($pKey) . '=' . urlencode($pVal);
    }
    
    // Prep context opts for stream
    $streamOpts = array(
      'http' => array(
        'method' => 'POST',
        'content' => (count($postData) > 0) ? implode('&', $postData) : NULL,
        'header' => implode("\r\n", $headers) . "\r\n"
      )
    );
    
    // Debugging is useful
    $this->log('Phirehose: Connecting to stream: ' . $url . ' with params: ' . str_replace("\n", '', 
      var_export($requestParams, TRUE)));
    
     // Create context + open stream
    $context = stream_context_create($streamOpts);
    @$this->conn = fopen($url, 'r', false, $context);
    
    // We handle errors this way to avoid fiddling/breaking application level set_error_handler() stuff
    if (!$this->conn) {
      $e = error_get_last();
      throw new ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']);
    }
    
    // Ensure set to non-blocking (important) 
    stream_set_blocking($this->conn, 0);
    
    // Connect always causes the filterChanged status to be cleared
    $this->filterChanged = FALSE;
  }
  
  /**
   * Method called as frequently as practical (every 5+ seconds) that is responsible for checking if filter predicates
   * (ie: track words or follow IDs) have changed. If they have, they should be set using the setTrack() and setFollow()
   * methods respectively. 
   * 
   * This should be implemented/overridden in any subclass implementing the FILTER method.
   *
   * @see setTrack()
   * @see setFollow()
   * @see Phirehose::METHOD_FILTER
   */
  protected function updateFilterPredicates()
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
   * Performs disconnect from stream (if connected) and cleanup
   */
  private function disconnect()
  {
    if (is_resource($this->conn)) {
      $this->log('Phirehose: Closing connection.');
      fclose($this->conn);
    }
    $this->conn = NULL;
  }
  
  /**
   * This is the one and only method that must be implemented additionally. As per the streaming API documentation,
   * statuses should NOT be processed within the same process that is performing collection 
   *
   * @param string $status
   */
  abstract public function enqueueStatus($status);
  
} // End of class