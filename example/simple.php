<?php
require_once('../lib/Phirehose.php');
/**
 * Simple example of using Phirehose to display the 'sample' twitter stream. 
 */
class SimpleConsumer extends Phirehose
{
  /**
   * Enqueue each status
   *
   * @param string $status
   */
  public function enqueueStatus($status)
  {
    /*
     * In this simple example, we will just display to STDOUT rather than enqueue.
     * NOTE: You should NOT be processing tweets at this point in a real application, instead they should be being
     *       enqueued and processed asyncronously from the collection process. 
     */
    $data = json_decode($status, true);
    if (is_array($data)) {
      print $data['user']['screen_name'] . ': ' . urldecode($data['text']) . "\n";
    }
  }
}

// Start streaming
$sc = new SimpleConsumer('username', 'password', Phirehose::METHOD_SAMPLE);
$sc->consume();