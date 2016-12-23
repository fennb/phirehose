<?php
require_once '../lib/Phirehose.php';
require_once '../lib/OauthPhirehose.php';
require_once 'twitter-auth-config.php';

/**
 * Example of using Phirehose to display a live filtered stream using geo locations
 */
class FilterTrackConsumer extends OauthPhirehose
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
        if (is_array($data) && isset($data['user']['screen_name'])) {
            print $data['user']['screen_name'] . ': ' . urldecode($data['text']) . "\n";
        }
    }
}

// Start streaming
$sc = new FilterTrackConsumer(OAUTH_TOKEN, OAUTH_SECRET, Phirehose::METHOD_FILTER);
$sc->setLocations(
    [
        [-122.75, 36.8, -121.75, 37.8], // San Francisco
        [-74, 40, -73, 41],             // New York
    ]
);
$sc->consume();
