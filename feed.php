<?php

header('Content-type: application/json');

require('db' . DIRECTORY_SEPARATOR . 'db.inc');
require('functions.inc');
require('config.inc');

// Get data for the requested database if available, otherwise default to the first database given
if (!empty($_REQUEST['_db'])) {
	$conn = $databases[$_REQUEST['_db']];

	if (is_null($conn))
		json_error('Invalid database requested');
}
else {
	$conn = array_shift(array_values($databases));
}

// Open new DeltaBravo wrapper
$db = new DatabaseConnection(
	$conn['hostname'],
	$conn['database'],
	$conn['username'],
	$conn['password'],
	$conn['type'],
	$conn['encoding'] 
	);

// Get all tables and primary columns from database
$tables = $db->list_tables()->execute();
$full_structure = array();
foreach ($tables as $k => $t) {
	$pkeys = $db->list_columns($t)->execute();
	foreach ($pkeys as $pkey) {
		$full_structure[$t][] = $pkey['Field'];
		if ($pkey['Key'] === 'PRI') {
			$tables[$pkey['Field']] = $t;
		}
	}
	unset($tables[$k]);
}

$primary_keys = array_flip($tables);

$feed->status = 1;

// Set $q to our table
if (!empty($_REQUEST['_q'])) {
	$q = $_REQUEST['_q'];

	// If $q isn't a valid table, die
	if (!in_array($q, $tables)) {
		json_error('Invalid table requested');
	}
}
// Or set $g to our global search term
elseif (!empty($_GET['_g'])) {
	$g = $_GET['_g'];
}
// Else error out
else {
	json_error('No table requested');
}

$method = strtolower($_SERVER['REQUEST_METHOD']);


// If we have a global search term, we globally search
if ($g) {
	if (!empty($search_tables)) {
		$search_results = array();
		foreach($search_tables as $table => $columns) {
			if (!in_array($table, $tables))
				continue;
			$query = $db->select($table);
			$where = array();
			foreach ($columns as $column) {
				if (!in_array($column, $full_structure[$table]))
					continue;
				$where[] = array($column, "%$g%", "LIKE", "OR");
			}
			unset($where[count($where) - 1][count($where[count($where) - 1]) - 1]);
			$query = $query->where($where);

			$query_results = $query->execute();
			if ($query_results) {
				$search_results[$table] = $query_results;
			}
		}
		json((object) $search_results);
	}
	else {
		json_error('Global search not configured');
	}
}
// If we have a standard query, call the appropriate REST file
else {
	$supported_methods = array('get', 'post');
	if (in_array($method, $supported_methods) and file_exists($rest_file = $method . '.php')) {
		require $rest_file;
	}
	else {
		json_error('Unsupported function requested: ' . $method);
	}
}