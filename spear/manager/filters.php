<?php
/**
 * Pure input filters and validators with no DB/session dependencies.
 *
 * Extracted from common_functions.php so PHPUnit can load these without a
 * MySQL connection or live session. common_functions.php requires this file
 * at load time, so callers continue to get the same symbols.
 */

if (!function_exists('doFilter')) {
    function doFilter($string, $type)
    {
        if ($type == 'ALPHA_NUM') {
            return preg_replace('/[^a-zA-Z0-9]+/', '', $string);
        }
        if ($type == 'ALPHA') {
            return preg_replace('/[^a-zA-Z]+/', '', $string);
        }
        if ($type == 'NUM') {
            return preg_replace('/[^0-9]+/', '', $string);
        }
        return $string;
    }
}

if (!function_exists('isValidEmail')) {
    function isValidEmail($email)
    {   // supports RFC 5322
        if (empty($email)) {
            return false;
        }

        $email = mb_convert_encoding($email, 'UTF-8', 'auto');
        $regex = '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/';

        return filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match($regex, $email);
    }
}
