<?php

class SimilarityAlg2
{
    /**
     * @param $str1
     * @param $str2
     *
     * @return float
     */
    public static function compareStrings($str1, $str2)
    {
        $pairs1 = self::wordLetterPairs(mb_strtolower($str1));
        $pairs2 = self::wordLetterPairs(mb_strtolower($str2));


        $union = count($pairs1) + count($pairs2);

        $intersection = count(array_intersect($pairs1, $pairs2));

        return (2.0 * $intersection) / $union;
    }

    /**
     * @param $str
     *
     * @return mixed
     */
    private static function wordLetterPairs($str)
    {
        $allPairs = array();

        // Tokenize the string and put the tokens/words into an array

        $words = explode(' ', $str);

        // For each word
        for ($w = 0; $w < count($words); $w++) {
            // Find the pairs of characters
            $pairsInWord = self::letterPairs($words[$w]);

            for ($p = 0; $p < count($pairsInWord); $p++) {
                $allPairs[$pairsInWord[$p]] = $pairsInWord[$p];
            }
        }

        return array_values($allPairs);
    }

    /**
     * @param $str
     *
     * @return array
     */
    private static function letterPairs($str)
    {
        $numPairs = mb_strlen($str) - 1;
        $pairs = array();

        for ($i = 0; $i < $numPairs; $i++) {
            $pairs[$i] = mb_substr($str, $i, 2);
        }

        return $pairs;
    }
}
