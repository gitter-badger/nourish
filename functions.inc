<?php

register_shutdown_function('catch_fatal');

function catch_fatal() {
    $e = error_get_last();
    switch ($e['type']) {
        case 1:
            $feed->status = 0;
            $feed->message = 'Fatal error: ' . $e['message'];
            json($feed);
            break;
        case 4:
            $feed->status = 0;
            $feed->message = 'Parse error: ' . $e['message'];
            json($feed);
            break;
    }
}

// ini_set('display_errors', 0);

function json($v, $format = TRUE, $debug = FALSE) {
    $v = utf8_encode_recursive($v);
    if (!is_object($v)) {
        if (is_array($v)) {
            if (is_assoc($v)) {
                $v = (object) $v;
            }
            else {
                $v = objectify($v);
            }
        }
        else {
            $v = objectify($v);
        }
    }

    if ($debug) {
        $v->debug = debug_backtrace();
    }
    $v = json_encode($v);
    echo $format ? json_format($v) : $v;
    exit;
}

function json_error($m, $a = NULL) {
    $feed->status = 0;
    $feed->message = $m;
    if ($a !== NULL){
        $feed->information = $a;
    }
    json($feed);
}

/**
 * Indents a flat JSON string to make it more human-readable.
 * @param string $json The original JSON string to process.
 * @return string Indented version of the original JSON string.
 */
function json_format($json) {

    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = '  ';
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i=0; $i<=$strLen; $i++) {

        // Grab the next character in the string.
        $char = substr($json, $i, 1);

        // Are we inside a quoted string?
        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;
        
        // If this character is the end of an element, 
        // output a new line and indent the next line.
        } else if(($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos --;
            for ($j=0; $j<$pos; $j++) {
                $result .= $indentStr;
            }
        }
        
        // Add the character to the result string.
        $result .= $char;

        // If the last character was the beginning of an element, 
        // output a new line and indent the next line.
        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos ++;
            }
            
            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }
        
        $prevChar = $char;
    }

    return $result;
}

function objectify($v) {
    $o->data = $v;
    return $o;
}

function utf8_encode_recursive($a) {
    if (is_scalar($a)) {
        $a = utf8_encode($a);
        return $a;
    }
    elseif (is_null($a)) {
        return null;
    }
    elseif (!is_resource($a)) {
        $b = array();
        foreach($a as $k => $v) {
            $b[utf8_encode($k)] = utf8_encode_recursive($v);
        }
        return $b;
    }
}

function json_decode_recursive($a) {
    if (is_scalar($a)) {
        $b = json_decode($a);
        if (is_scalar($b) or is_null($b)) {
            return utf8_decode(newline_format($a));
        }
        else {
            return json_decode_recursive($b);
        }
    }
    elseif (is_null($a)) {
        return null;
    }
    elseif (!is_resource($a)) {
        $b = array();
        foreach ($a as $k => $v) {
            if ($k === '_empty_') {
                $k = '';
            }
            $b[utf8_decode($k)] = json_decode_recursive($v);
        }
        return $b;
    }
}

function newline_format($s) {
    return preg_replace("/\r\n?/", "\n", $s);
}

function is_assoc($array) {
    return (bool) count(@array_filter(@array_keys($array), 'is_string'));
}

/**
 * Sort array by given subkey using asort()
 * Array is returned and is not passed by reference
 * Stands for "sub-key sort" and "sub-key reverse sort"
 */
function sksort($a, $subkey, $sort_flags = SORT_REGULAR) {
    foreach ($a as $k => $v) {
        $b[$k] = strtolower($v[$subkey]);
    }
    asort($b, $sort_flags);
    foreach ($b as $key=>$val) {
        $c[$key] = $a[$key];
    }
    return $c;
}

function skrsort($a, $subkey, $sort_flags = SORT_REGULAR) {
    foreach ($a as $k => $v) {
        $b[$k] = strtolower($v[$subkey]);
    }
    arsort($b, $sort_flags);
    foreach ($b as $key => $val) {
        $c[$key] = $a[$key];
    }
    return $c;
}

// Functions for above code
// Recursive function to make sure we grab all the fields from all the tables - let's you WHERE on non-primary-table columns
function build_fields($fields, $queried_tables, $primary_keys, $tables, $db, $full_structure = '', $table_structure = '') {
    if ($table_structure !== '' and is_string($table_structure)) {
        $table_structure_name = $table_structure;
        global ${$table_structure};
    }
    foreach ($fields as $field) {
        if (in_array($field, $primary_keys) and !in_array($tables[$field], $queried_tables)) {
            $queried_tables[] = $tables[$field];
            $more_fields = $full_structure[$tables[$field]];
            $table_structure[$tables[$field]] = $more_fields;
            $fields = array_values(array_unique(array_merge($fields, $more_fields)));
            $fields = array_values(array_unique(array_merge(build_fields($fields, $queried_tables, $primary_keys, $tables, $db, $full_structure, $table_structure_name))));
        }
    }
    return $fields;
}