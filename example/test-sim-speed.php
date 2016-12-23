<?php
require_once '../lib/SimilarityAlg.php';
require_once '../lib/SimilarityAlg2.php';


class TestSpeed
{
    private $alg;

    private $list;

    function __construct($alg, $list)
    {
        $this->alg = $alg;
        $this->list = $list;
    }

    public function testSim($text)
    {
        $size = sizeof($this->list) - 1;
        for ($i = $size; $i >= 0; --$i) {
            $this->alg->compareStrings($text, $this->list[$i]);
        }
    }
}

$similarityIndex = new LetterPairSimilarity();
$similarityIndex2 = new SimilarityAlg2();
$data = file('./data');
$bench = new TestSpeed($similarityIndex2, $data);
$start_time = microtime(TRUE);
$test = 'RT @AlbertoNardelli: Even before #Berlin facts are known, one thing is clear: far-right, trolls &amp; propaganda are targeting Merkel &amp; 2017 ht';
$bench->testSim($test);
$end_time = microtime(TRUE);

print $end_time - $start_time;


