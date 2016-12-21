<?php
require_once('../lib/OauthPhirehose.php');
require_once 'twitter-auth-config.php';

/**
 * Barebones example of using OauthPhirehose to do site streams.
 *
 * NOTE: Completely UNTESTED.
 */
class MyUserConsumer extends OauthPhirehose
{
  /**
   * First response looks like this:
   *    $data=array('friends'=>array(123,2334,9876));
   *
   * Each tweet of your friends looks like:
   *   [id] => 1011234124121
   *   [text] =>  (the tweet)
   *   [user] => array( the user who tweeted )
   *   [entities] => array ( urls, etc. )
   *
   * Every 30 seconds we get the keep-alive message, where $status is empty.
   *
   * When the user adds a friend we get one of these:
   *    [event] => follow
   *    [source] => Array(   my user   )
   *    [created_at] => Tue May 24 13:02:25 +0000 2011
   *    [target] => Array  (the user now being followed)
   *
   * @param string $status
   */
  public function enqueueStatus($status)
  {
    /*
     * In this simple example, we will just display to STDOUT rather than enqueue.
     * NOTE: You should NOT be processing tweets at this point in a real application, instead they
     *  should be being enqueued and processed asyncronously from the collection process.
     */
    $data = json_decode($status, true);
    echo date("Y-m-d H:i:s (").strlen($status)."):".print_r($data,true)."\n";
  }

}

// Start streaming
$sc = new MyUserConsumer(OAUTH_TOKEN, OAUTH_SECRET, Phirehose::METHOD_SITE);
$sc->setFollow(array(
    1234, 5678, 901234573   //The user IDs of the twitter accounts to follow. All of
        //these users must have given your app permission.
    ));
$sc->consume();
