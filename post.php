<?

// Allow user modules to hook POST fields
$files = glob('./modules/*.php');
$hooks = array();
foreach ($files as $file) {
	include_once($file);

	// Get names of hooks from $files array
	$hooks[] = basename($file, '.php');
}

// Loop over fields and call their hooks
foreach ($full_structure[$q] as $field) {
	$data['q'][$field] = $_POST['_' . $field];
	foreach ($hooks as $hook) {
		$func = $hook . '_' . $method . '_' . $field;
		$data[$hook][$field] = $func($data[$field]);
	}
}

// Resolve data differences.
// At most one method can change the value of the POSTed data.
// If more than one method changes this, we throw an error that displays the conflicting values and the names of their hooks.
$errors = array();
foreach ($data['q'] as $field => $input) {
	$count = 0;
	foreach ($hooks as $hook) {
		if ($input === $data[$hook][$field])
			continue;
		else {
			++$count;
			$data['q'][$field] = $data[$hook][$field];
		}

		if ($count > 1)
			$errors[] = "$hook_$method_$field is trying to modify the same data as another hook. To prevent unexpected results, this requested operation was cancelled.";
	}
}

// If we have errors, call json_error with our $errors array attached as information
if (count($errors) > 0)
	json_error('An error occurred when trying to perform the requested operation.', $errors);
else {
	// No errors, let's either update or insert our new data

	// Set magic word variables
	$_limit = $_POST['_limit'];

	// Get fields from requested table
	// Do we want to get all fields from all tables first? Probably. How to do this?
	$fields = $full_structure[$q];

	// Build list of fields that we want to join on, as well as the general table structure for remapping columns
	$queried_tables = array($q);
	$table_structure[$q] = $fields;

	// Build WHERE from $field_op and $field_comb values
	// Combinator values don't work very well because $_POST variables get sorted somehow. How to fix?
	// Use $_POST['comb_order'] = fid,id,aid or similar? 
	$where = array();
	foreach ($fields as $field) {
		if (!empty($_POST[$field])) {
			$where[] = array($field, $_POST[$field], (!empty($_POST[$field . '__op']) ? $_POST[$field . '__op'] : '='), (!empty($_POST[$field . '__comb']) ? $_POST[$field . '__comb'] : 'AND'));
		}
	}

	// If we have a $where set, we're doing an UPDATE.
	// Otherwise, INSERT.
	if (!empty($where)) {
		$query = $db->update($q, $data['q'])->where($where);
	}
	else {
		$query = $db->insert($q, $data['q']);
	}

	// Set limit and offset based on GET request
	if (trim((string) $_limit) !== '') {
		if (!$_offset)
			$_offset = 0;
		$query = $query->limit($_limit, $_offset);
	}

	$results->results = $query->execute();
	if (!$results->results) {
		json_error('Query failed', array_merge($query->execute(1,1), array('return_value' => $results->results)));
	}
	// Uncomment the following line to include the query in the returned JSON for inspection
	$results->query = $query->execute(1,1);

	json((object) array_merge((array) $feed, (array) $results));
}