# Phirehose #
A PHP interface to the Twitter Streaming API (firehose, etc). This library makes it easy to connect to and consume the Twitter stream via the Streaming API.

See:
  * https://github.com/fennb/phirehose/wiki/Introduction and 
  * http://dev.twitter.com/pages/streaming_api

## Goals ##
  * Provide a simple interface to the Twitter Streaming API for PHP applications
  * Comply to Streaming API recommendations for error handling, reconnection, etc
  * Encourage well-behaved streaming API clients
  * Operate independently of PHP extensions (ie: shared memory, PCNTL, etc)

In short:

	require_once('Phirehose.php');
	class MyStream extends Phirehose
	{
	  public function enqueueStatus($status)
	  {
	    print $status;
	  }
	}
	
	$stream = new MyStream('username', 'password');
	$stream->consume();


## What this library does do ##
  * Handles connection/authentication to the twitter streaming API
  * Consumes the stream handing off each status to be enqueued by a method of your choice
  * Handles graceful reconnection/back-off on connection and API errors
  * Monitors/reports performance metrics and errors

## What this library doesn't do ##
  * Decode/process tweets
  * Provide any sort of queueing mechanism for asynchronous processing (though some examples are included)
  * Provide any sort of inter-process communication
  * Provide any non-streaming API functionality (ie: user profile info, search, etc)

Please see the wiki for [documentation](https://github.com/fennb/phirehose/wiki/Introduction).

If you have any additional questions, head over to the Phirehose Users group [http://groups.google.com/group/phirehose-users]

It's recommended that you join (or at least regularly check) this group if you're actively using Phirehose so I can let you know when I release new versions.

Additionally, if you'd like to contact me directly, I'm [@fennb](http://twitter.com/fennb) on twitter.
