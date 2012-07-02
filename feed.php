<?php

header('Content-type: application/json');

require('db' . DIRECTORY_SEPARATOR . 'db.inc');
require('functions.inc');
require('config.inc');

$db = new DatabaseConnection(
	DB_HOSTNAME,
	DB_DATABASE,
	DB_USERNAME,
	DB_PASSWORD,
	DB_TYPE,
	DB_ENCODING 
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
if (!empty($_GET['_q'])) {
	$q = $_GET['_q'];
}
// Or set $g to our global search term
elseif (!empty($_GET['_g'])) {
	$g = $_GET['_g'];
}
// Else error out
else {
	json_error('No feed requested');
}


// If we have a standard table query, this goes
if ($q) {

	// If $q isn't a valid table, die
	if (!in_array($q, $tables)) {
		json_error('Invalid feed requested');
	}

	// Set magic word variables
	$_aliasing = (isset($_GET['_aliasing']) and $_GET['_aliasing'] !== '');
	$_primary = (isset($_GET['_primary']) and $_GET['_primary'] !== '');
	$_overwrite = ((isset($_GET['_overwrite']) and (bool) $_GET['_overwrite']) or $_GET['_overwrite'] === NULL or $_GET['overwrite'] === '');
	$_join = $_GET['_join'];
	$_order = $_GET['_order'];
	$_limit = $_GET['_limit'];
	$_offset = $_GET['_offset'];
	$_search = $_GET['_search'];

	$cols = (!empty($_GET['_cols']) ? explode(',', $_GET['_cols']) : '*');

	// Get fields from requested table
	// Do we want to get all fields from all tables first? Probably. How to do this?
	$fields = $db->select($q)->limit(1)->execute();
	$fields = array_keys($fields[0]);

	// Build list of fields that we want to join on, as well as the general table structure for remapping columns
	$queried_tables = array($q);
	$table_structure[$q] = $fields;
	$fields = build_fields($fields, $queried_tables, $primary_keys, $tables, $db, $full_structure, 'table_structure');

	// Sanitize incoming JOIN requests
	if ($_join) {
		$join_ = array();
		foreach ($_join as $_table => $_column) {
			if (in_array($_table, $tables) and in_array($_column, $full_structure[$_table])) {
				$join_[$_table] = $_column;
				if ($_aliasing)
					$table_structure[$_table] = $full_structure[$_table];
			}
		}
		$_join = $join_;
	}

	if (!$_aliasing) {
		$query = $db->select($q, $cols);
	}
	else {
		$query = $db->select($q, $cols, $table_structure);
	}


	// Build WHERE from $field_op and $field_comb values
	// Combinator values don't work very well because $_GET variables get sorted somehow. How to fix?
	// Use $_GET['comb_order'] = fid,id,aid or similar? 
	$where = array();
	foreach ($fields as $field) {
		if (!empty($_GET[$field])) {
			$where[] = array($field, $_GET[$field], (!empty($_GET[$field . '__op']) ? $_GET[$field . '__op'] : '='), (!empty($_GET[$field . '__comb']) ? $_GET[$field . '__comb'] : 'AND'));
		}
	}

	if (!empty($_search)) {
		$where_search = array();
		foreach($full_structure[$q] as $column)
			$where_search[] = array("`$q`.`$column`", "%$_search%", 'LIKE', 'OR');
		unset($where_search[count($where_search)-1][count($where_search[count($where_search)-1])-1]);
		$where[] = $where_search;
	}

	// If we have a where clause to be included, unset the last combinator (AND, OR, etc.)
	if (!empty($where)) {
		if(empty($_search))
			unset($where[count($where)-1][count($where[count($where)-1])-1]);
		$query = $query->where($where);
	}

	// Build JOINs from available fields
	foreach ($fields as $field) {
		if (in_array($field, $primary_keys) and $tables[$field] != $q) {
			$query = $query->join($tables[$field], $field);
		}
	}

	// Build JOINs from GET request
	if ($_join) {
		foreach ($_join as $_table => $_column) {
			// We've already sanitized above, so we can just append these joins without worry
			$query = $query->join($_table, $_column);
		}
	}

	// Set limit and offset based on GET request
	if (trim((string) $_limit) !== '') {
		if (!$_offset)
			$_offset = 0;
		$query = $query->limit($_limit, $_offset);
	}

	// Set ordering based on GET
	if (!empty($_order)) {
		$directions = array('ASC', 'DESC');
		foreach($_order as $field => $direction) {
			$direction = strtoupper($direction);
			if (in_array($field, $fields) and in_array($direction, $directions));
			$query = $query->order($field, $direction);
		}
	}

	$results->results = $query->execute();
	if (!$results->results) {
		json_error('Query failed', $query->execute(1,1));
	}
	// Uncomment the following line to include the query in the returned JSON for inspection
	$results->query = $query->execute(1,1);

	// Returns the results array keyed by the key provided in _primary
	// Later values WILL overwrite earlier values if the key is not unique
	if ($_primary) {
		if (count($results->results) > 0) {
			if (!$_aliasing) {
				if (in_array($_GET['_primary'], array_keys($results->results[0]))) {
					foreach ($results->results as $r) {
						$key = $r[$_GET['_primary']];
						unset($r[$_GET['_primary']]);
						if ($_overwrite) {
							$results_[$key] = $r;
						}
						else {
							$results_[$key][] = $r;
						}
					}
				}
			}
			else {
				if (isset($_GET['_primary_table'])) {
					$primary = $_GET['_primary_table'] . '__' . $_GET['_primary'];
					if (in_array($primary, array_keys($results->results[0]))) {
						foreach ($results->results as $r) {
							$key = $r[$primary];
							unset($r[$primary]);
							if ($_overwrite) {
								$results_[$key] = $r;
							}
							else {
								$results_[$key][] = $r;
							}
						}
					}
				}
				else {
					if (in_array($_GET['_primary'], array_keys($table_structure[$q]))) {
						$primary = $q . '__' . $_GET['_primary'];
						if (in_array($primary, array_keys($results->results[0]))) {
							foreach ($results->results as $r) {
								$key = $r[$primary];
								unset($r[$primary]);
								if ($_overwrite) {
									$results_[$key] = $r;
								}
								else {
									$results_[$key][] = $r;
								}
							}
						}
					}
				}
			}
			if (isset($results_) and !empty($results_)) {
				$results->results = $results_;
			}
		}
	}

	$results->results = json_decode_recursive($results->results);

	json((object) array_merge((array) $feed, (array) $results));
}
// If we have a global search term, we globally search
elseif ($g) {
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

else {
	json_error('No function requested');
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