<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Util;

class ArrayString
{
    /**
     * Transforms a 1-d array into a comma-separated list of single-quote(')-wrapped values.
     *
     * @param array $values
     *
     * @return string
     */
    public function arrayToCommaSeparatedValues(array $values)
    {
        $array = [];

        foreach ($values as $value) {
            $array[] = "'$value'";
        }

        return implode(',', $array);
    }

    /**
     * Transform a 2-d array into a comma-separated list of update prepared placeholders.
     * "arr2update"
     *
     * @param array $updateArray
     *
     * @return string
     */
    public function arrayToCommaSeparatedUpdateString(array $updateArray)
    {
        $array = [];

        foreach ($updateArray as $updateKey => $updateValue) {
            $array[] = "$updateKey=?";
        }

        return implode(',', $array);
    }

    /**
     * Transforms a 1-d array into a comma-separated list of unnamed placeholders.
     * "arr2values"
     *
     * @param array $array
     *
     * @return string
     */
    public function arrayToCommaSeparatedValueString(array $array)
    {
        return substr(str_repeat('?,', count($array)), 0, -1);
    }

    /**
     * Transforms a comma-separated list to a 1-d array of trimmed values.
     * "csl2arr"
     *
     * @param string $list
     * @param string $separator
     *
     * @return string[]
     */
    public function commaSeparatedListToTrimmedArray(string $list, string $separator = ',')
    {
        $array = explode($separator, $list);

        foreach ($array as $i => $value) {
            $array[$i] = trim($value);
        }

        return $array;
    }

    /**
     * Build url slug from string
     *
     * @param string $string
     * @param bool   $allowSlash
     *
     * @return string
     * @noinspection PhpUnnecessaryLocalVariableInspection
     */
    public function slugify(string $string, bool $allowSlash = false)
    {
        $regex = $allowSlash ? '[^a-z0-9-/]' : '[^a-z0-9-]';

        $string = strtolower(trim($string));
        $string = preg_replace("|$regex|", '-', $string);
        $string = preg_replace('|-+|', '-', $string);
        $string = preg_replace('|-$|', '', $string);

        return $string;
    }
}
