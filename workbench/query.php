<?php

require_once ('soxl/QueryObjects.php');
require_once ('session.php');
require_once ('shared.php');

$defaultSettings['numFilters'] = 1;
//clear the form if the user changes the object
if (isset($_POST['justUpdate']) && $_POST['justUpdate'] == true){
	$queryRequest = new QueryRequest($defaultSettings);
	$queryRequest->setObject($_POST['QB_object_sel']);
} else {
	//create a new QueryRequest object to save named and/or last query
	$lastQr = new QueryRequest($_REQUEST);	
		
	//save last query. always do this even if named.
	if((isset($_POST['querySubmit']) && $_POST['querySubmit']=='Query') || (isset($_POST['doSaveQr']) && $_POST['doSaveQr'] == 'Save' )){
		$_SESSION['lastQueryRequest'] = $lastQr;
	} 
	
	$persistedSavedQueryRequestsKey = "PSQR@";
	if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] == 'USER'){
		$persistedSavedQueryRequestsKey .= $_SESSION['getUserInfo']->userId . "@" . $_SESSION['getUserInfo']->organizationId;
	} else if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] == "ORG"){
		$persistedSavedQueryRequestsKey .= $_SESSION['getUserInfo']->organizationId;
	} else if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] == 'ALL'){
		$persistedSavedQueryRequestsKey .= "ALL";
	}
	
	//populate queryRequest for this page view. first see if user wants to retreive a saved query,
	//then see if there was a last query, else just show a null query with default object.
	if(isset($_REQUEST['getQr']) && $_REQUEST['getQr'] != "" && isset($_SESSION['savedQueryRequests'][$_REQUEST['getQr']])){
		$queryRequest = $_SESSION['savedQueryRequests'][$_REQUEST['getQr']];
		$_POST['querySubmit'] = 'Query'; //simulate the user clicking 'Query' to run immediately
	} else if(isset($_SESSION['lastQueryRequest'])){
		$queryRequest = $_SESSION['lastQueryRequest'];
	} else {
		$queryRequest = new QueryRequest($defaultSettings);
		$queryRequest->setObject($_SESSION['default_object']);
		if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] != 'NONE' && !isset($_SESSION['savedQueryRequests']) && isset($_COOKIE[$persistedSavedQueryRequestsKey])) {
			$_SESSION['savedQueryRequests'] = unserialize($_COOKIE[$persistedSavedQueryRequestsKey]);
		}
	}

	//clear  all saved queries in scope if user requests
	if(isset($_POST['clearAllQr']) && $_POST['clearAllQr'] == 'Clear All'){
		$_SESSION['savedQueryRequests'] = null;
		if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] != 'NONE'){
			setcookie($persistedSavedQueryRequestsKey,null,time()-3600);
		}
	} 
	
	//save as named query
	if(isset($_POST['doSaveQr']) && $_POST['doSaveQr'] == 'Save' && isset($_REQUEST['saveQr']) && strlen($_REQUEST['saveQr']) > 0){
		$_SESSION['savedQueryRequests'][htmlspecialchars($_REQUEST['saveQr'],ENT_QUOTES,'UTF-8')] = $lastQr;
		if($_SESSION['config']['savedQueriesAndSearchesPersistanceLevel'] != 'NONE'){
			setcookie($persistedSavedQueryRequestsKey,serialize($_SESSION['savedQueryRequests']),time()+60*60*24*7);
		}
	} 
}

//Main form logic: When the user first enters the page, display form defaulted to
//show the query results with default object selected on a previous page, otherwise
// just display the blank form. When the user selects the SCREEN or CSV options, the
//query is processed by the correct function
if(isset($_POST['queryMore']) && isset($_SESSION['queryLocator'])){
	print "<body onLoad='toggleFieldDisabled();'>";
	require_once ('header.php');
	$queryRequest->setExportTo('screen');
	show_query_form($queryRequest);
	$queryTimeStart = microtime(true);
	$records = query(null,'QueryMore',$_SESSION['queryLocator']);
	$queryTimeEnd = microtime(true);
	$queryTimeElapsed = $queryTimeEnd - $queryTimeStart;
	show_query_result($records,$queryTimeElapsed);
	include_once('footer.php');
} else if (isset($_POST['querySubmit']) && $_POST['querySubmit']=='Query' && $queryRequest->getSoqlQuery() != null && $queryRequest->getExportTo() == 'screen') {
	print "<body onLoad='toggleFieldDisabled();'>";
	require_once ('header.php');
	$queryRequest->setExportTo('screen');
	show_query_form($queryRequest);
	$queryTimeStart = microtime(true);
	$records = query($queryRequest->getSoqlQuery(),$queryRequest->getQueryAction());
	$queryTimeEnd = microtime(true);
	$queryTimeElapsed = $queryTimeEnd - $queryTimeStart;
	show_query_result($records,$queryTimeElapsed);
	include_once('footer.php');
} elseif (isset($_POST['querySubmit']) && $_POST['querySubmit']=='Query' && $queryRequest->getSoqlQuery() != null && $queryRequest->getExportTo() == 'csv') {
	if (!substr_count($_POST['soql_query'],"count()")){
		$records = query($queryRequest->getSoqlQuery(),$queryRequest->getQueryAction(),null,true);
		export_query_csv($records,$queryRequest->getExportTo());
	} else {
		print "<body onLoad='toggleFieldDisabled();'>";
		require_once ('header.php');
		$queryRequest->setExportTo('csv');
		show_query_form($queryRequest);
		print "</form>"; //could include inside because if IE page loading bug
		print "<p>&nbsp;</p>";
		show_error("count() is not supported for CSV file export. Change export to Browser or choose fields and try again.");
		include_once('footer.php');
	}
} else {
	print "<body onLoad='toggleFieldDisabled();'>";
	require_once ('header.php');
	$queryRequest->setExportTo('screen');
	$queryRequest->setQueryAction('Query');
	show_query_form($queryRequest);
	print "</form>"; //could include inside because if IE page loading bug
	include_once('footer.php');
}



//Show the main SOQL query form with default query or last submitted query and export action (screen or CSV)

function show_query_form($queryRequest){

	if ($queryRequest->getObject()){
		$describeSObject_result = describeSObject($queryRequest->getObject(), true);
	} else {
		show_info('First choose an object to use the SOQL builder wizard.');
	}

	print "<script>\n";
	
	print "var field_type_array = new Array();\n";
	if(isset($describeSObject_result)){
		foreach($describeSObject_result->fields as $fields => $field){
			print " field_type_array[\"$field->name\"]=[\"$field->type\"];\n";
		}
	}
	
	$ops = array(
		'=' => '=',
		'!=' => '&ne;',
		'<' => '&lt;',
		'<=' => '&le;',
		'>' => '&gt;',
		'>=' => '&ge;',
		'starts' => 'starts with',
		'ends' => 'ends with',
		'contains' => 'contains',
		'IN' => 'in',
		'NOT IN' => 'not in',
		'INCLUDES' => 'includes',
		'EXCLUDES' => 'excludes'
	);

	
	print "var compOper_array = new Array();\n";
	foreach($ops as $op_value => $op_label){
		print " compOper_array[\"$op_value\"]=[\"$op_label\"];\n";
	}
	
	print <<<QUERY_BUILDER_SCRIPT

function parentChildRelationshipQueryBlocker(){
    var soql = document.getElementById('soql_query_textarea').value.toUpperCase();
    
	if(soql.indexOf('(SELECT') != -1 && soql.indexOf('IN (SELECT') == -1 && document.getElementById('export_action_csv').checked){
		return confirm ("Export of parent-to-child relationship queries to CSV are not yet supported by Workbench and may give unexpected results. Are you sure you wish to continue?");
	}
	
}

function doesQueryHaveName(){
    var saveQr = document.getElementById('saveQr');
	if(saveQr.value == null || saveQr.value.length == 0){
		alert('Query must have a name to save.');
		return false;
	}	
	
	return true;
}


function toggleFieldDisabled(){
	var QB_field_sel = document.getElementById('QB_field_sel');

	if(document.getElementById('QB_object_sel').value){
		QB_field_sel.disabled = false;
	} else {
		QB_field_sel.disabled = true;
	}


	var isFieldSelected = false;
	for (var i = 0; i < QB_field_sel.options.length; i++)
		if (QB_field_sel.options[i].selected)
			isFieldSelected = true;

	if(isFieldSelected){
			document.getElementById('QB_orderby_field').disabled = false;
			document.getElementById('QB_orderby_sort').disabled = false;
			document.getElementById('QB_nulls').disabled = false;
			document.getElementById('QB_limit_txt').disabled = false;
			
			document.getElementById('QB_filter_field_0').disabled = false;
			if(document.getElementById('QB_filter_field_0').value){
				document.getElementById('QB_filter_value_0').disabled = false;
				document.getElementById('QB_filter_compOper_0').disabled = false;
			} else {
				document.getElementById('QB_filter_value_0').disabled = true;
				document.getElementById('QB_filter_compOper_0').disabled = true;
			}
	} else{
			document.getElementById('QB_filter_field_0').disabled = true;
			document.getElementById('QB_filter_compOper_0').disabled = true;
			document.getElementById('QB_filter_value_0').disabled = true;
			document.getElementById('QB_orderby_field').disabled = true;
			document.getElementById('QB_orderby_sort').disabled = true;
			document.getElementById('QB_nulls').disabled = true;
			document.getElementById('QB_limit_txt').disabled = true;
	}

	var allPreviousRowsUsed = true;
	for(var r = 1; r < document.getElementById('numFilters').value; r++){
		var lastRow = r-1;
		var thisRow = r;
		
		if (isFieldSelected && allPreviousRowsUsed && document.getElementById('QB_filter_field_' + lastRow).value && document.getElementById('QB_filter_compOper_' + lastRow).value && document.getElementById('QB_filter_value_' + lastRow).value){
			document.getElementById('QB_filter_field_' + thisRow).disabled = false;
			if(document.getElementById('QB_filter_field_' + thisRow).value){
				document.getElementById('QB_filter_value_' + thisRow).disabled = false;
				document.getElementById('QB_filter_compOper_' + thisRow).disabled = false;
			} else {
				document.getElementById('QB_filter_value_' + thisRow).disabled = true;
				document.getElementById('QB_filter_compOper_' + thisRow).disabled = true;
			}
		} else {
			allPreviousRowsUsed = false;
			document.getElementById('QB_filter_field_' + thisRow).disabled = true;
			document.getElementById('QB_filter_compOper_' + thisRow).disabled = true;
			document.getElementById('QB_filter_value_' + thisRow).disabled = true;
		}
	}
}

function updateObject(){
  document.query_form.justUpdate.value = 1;
  document.query_form.submit();
}

function build_query(){
	toggleFieldDisabled();
	var QB_object_sel = document.getElementById('QB_object_sel').value;
	var QB_field_sel = document.getElementById('QB_field_sel');
	QB_fields_selected = new Array();
	for (var i = 0; i < QB_field_sel.options.length; i++){
		if (QB_field_sel.options[i].selected){
			QB_fields_selected.push(QB_field_sel.options[i].value);
		}
	}

	var soql_select = '';
	if(QB_fields_selected.toString().indexOf('count()') != -1 && QB_fields_selected.length > 1){
		alert('Warning: Choosing count() with other fields will result in a malformed query. Unselect either count() or the other fields to continue.');
	} else	if (QB_fields_selected.length > 0){
		var soql_select = 'SELECT ' + QB_fields_selected + ' FROM ' + QB_object_sel;
	}

	soql_where = '';
	for(var f = 0; f < document.getElementById('numFilters').value; f++){
	
		var QB_filter_field = document.getElementById('QB_filter_field_' + f).value;
		var QB_filter_compOper = document.getElementById('QB_filter_compOper_' + f).value;
		var QB_filter_value = document.getElementById('QB_filter_value_' + f).value;
		
		var soql_where_logicOper = '';
		if(f > 0){
			soql_where_logicOper = ' AND ';
		}	
		
		if (QB_filter_field && QB_filter_compOper && QB_filter_value){
			if (QB_filter_compOper == 'starts'){
				QB_filter_compOper = 'LIKE'
				QB_filter_value = QB_filter_value + '%';
			} else if (QB_filter_compOper == 'ends'){
				QB_filter_compOper = 'LIKE'
				QB_filter_value = '%' + QB_filter_value;
			} else if (QB_filter_compOper == 'contains'){
				QB_filter_compOper = 'LIKE'
				QB_filter_value = '%' + QB_filter_value + '%';
			}
			
			
			if (QB_filter_compOper == 'IN' || 
				QB_filter_compOper == 'NOT IN' ||
				QB_filter_compOper == 'INCLUDES' || 
				QB_filter_compOper == 'EXCLUDES'){
					QB_filter_value_q = '(' + QB_filter_value + ')';
			} else if ((QB_filter_value == 'null') ||
				(field_type_array[QB_filter_field] == "datetime") ||
				(field_type_array[QB_filter_field] == "date") ||
				(field_type_array[QB_filter_field] == "currency") ||
				(field_type_array[QB_filter_field] == "percent") ||
				(field_type_array[QB_filter_field] == "double") ||
				(field_type_array[QB_filter_field] == "int") ||
				(field_type_array[QB_filter_field] == "boolean")){
					QB_filter_value_q = QB_filter_value;
			} else {
				QB_filter_value_q = '\'' + QB_filter_value + '\'';
			}

			soql_where += soql_where_logicOper + QB_filter_field + ' ' + QB_filter_compOper + ' ' + QB_filter_value_q;
		} else {
			break;
		}
	}
	soql_where = soql_where != '' ? ' WHERE ' + soql_where : '';

	var QB_orderby_field = document.getElementById('QB_orderby_field').value;
	var QB_orderby_sort = document.getElementById('QB_orderby_sort').value;
	var QB_nulls = document.getElementById('QB_nulls').value;
	if (QB_orderby_field){
		var soql_orderby = ' ORDER BY ' + QB_orderby_field + ' ' + QB_orderby_sort;
		if (QB_nulls)
			soql_orderby = soql_orderby + ' NULLS ' + QB_nulls;
	} else
		var soql_orderby = '';


	var QB_limit_txt = document.getElementById('QB_limit_txt').value;
	if (QB_limit_txt)
		var soql_limit = ' LIMIT ' + QB_limit_txt;
	else
		var soql_limit = '';

	if (soql_select)
		document.getElementById('soql_query_textarea').value = soql_select + soql_where + soql_orderby + soql_limit ;

}


function addFilterRow(filterRowNum, defaultField, defaultCompOper, defaultValue){
	//build the row inner html
	var row = filterRowNum == 0 ? "<br/>Filter results by:<br/>" : "" ;
	row += 	"<select id='QB_filter_field_" + filterRowNum + "' name='QB_filter_field_" + filterRowNum + "' style='width: 16em;' onChange='build_query();' onkeyup='build_query();'>" +
			"<option value=''></option>";
	
	for (var field in field_type_array) {
		row += "<option value='" + field + "'";
		if (defaultField == field) row += " selected='selected' ";
		row += "'>" + field + "</option>";
	} 	
	
	row += "</select>&nbsp;" +
			"" +
			"<select id='QB_filter_compOper_" + filterRowNum + "' name='QB_filter_compOper_" + filterRowNum + "' style='width: 10em;' onChange='build_query();' onkeyup='build_query();'>";

	for (var opKey in compOper_array) {
		row += "<option value='" + opKey + "'";
		if (defaultCompOper == opKey) row += " selected='selected' ";
		row += ">" + compOper_array[opKey] + "</option>";
	} 
	
	defaultValue = defaultValue != null ? defaultValue : "";
	row +=  "</select>&nbsp;" +
			"<input type='text' id='QB_filter_value_" + filterRowNum + "' size='31' name='QB_filter_value_" + filterRowNum + "' value='" + defaultValue + "' onkeyup='build_query();' />";
			

	//add to the DOM
	var newFilterCell = document.createElement('td');
	newFilterCell.setAttribute('colSpan','4');
	newFilterCell.setAttribute('vAlign','top');
	newFilterCell.setAttribute('nowrap','true');
	newFilterCell.innerHTML = row;

	var newPlusCell = document.createElement('td');
	newPlusCell.setAttribute('id','filter_plus_cell_' + filterRowNum);
	newPlusCell.setAttribute('vAlign','bottom');
	newPlusCell.innerHTML = "<img id='filter_plus_button' src='images/plus_icon.jpg' onclick='addFilterRow(document.getElementById(\"numFilters\").value++);toggleFieldDisabled();' onmouseover='this.style.cursor=\"pointer\";'  style='padding-top: 4px;'/>";
	
	var newFilterRow = document.createElement('tr');
	newFilterRow.setAttribute('id','filter_row_' + filterRowNum);
	newFilterRow.appendChild(newFilterCell);
	newFilterRow.appendChild(newPlusCell);
	
	document.getElementById('QB_right_sub_table').getElementsByTagName("TBODY").item(0).appendChild(newFilterRow);
	
	if(filterRowNum > 0){
		var filter_plus_button = document.getElementById('filter_plus_button');
		filter_plus_button.parentNode.removeChild(filter_plus_button);
	}
	
	//expand the field list so it looks right
	document.getElementById('QB_field_sel').size += 2;
}

</script>
QUERY_BUILDER_SCRIPT;
	

	if($_SESSION['config']['autoJumpToResults']){
		print "<form method='POST' name='query_form' action='$_SERVER[PHP_SELF]#qr'>\n";
	} else {
		print "<form method='POST' name='query_form' action='$_SERVER[PHP_SELF]'>\n";
	}
	print "<input type='hidden' name='justUpdate' value='0' />";
	print "<input type='hidden' id='numFilters' name='numFilters' value='" . count($queryRequest->getFilters()) ."' />";
	print "<p class='instructions'>Choose the object, fields, and critera to build a SOQL query below:</p>\n";
	print "<table border='0' width=1>\n";
	print "<tr><td valign='top' width='1'>Object:";

	myGlobalSelect($queryRequest->getObject(), 'QB_object_sel', "16", "onChange='updateObject();'", "queryable");

	print "<p/>Fields:<select id='QB_field_sel' name='QB_field_sel[]' multiple='mutliple' size='4' style='width: 16em;' onChange='build_query();'>\n";
	if(isset($describeSObject_result)){

		print   " <option value='count()'";
		if($queryRequest->getFields() != null){ //check to make sure something is selected; otherwise warnings will display
			foreach ($queryRequest->getFields() as $selected_field){
				if ('count()' == $selected_field) print " selected='selected' ";
			}
		}
		print ">count()</option>\n";

		print ">$field->name</option>\n";
		foreach($describeSObject_result->fields as $fields => $field){
			print   " <option value='$field->name'";
			if($queryRequest->getFields() != null){ //check to make sure something is selected; otherwise warnings will display
				foreach ($queryRequest->getFields() as $selected_field){
					if ($field->name == $selected_field) print " selected='selected' ";
				}
			}
			print ">$field->name</option>\n";
		}
	}
	print "</select></td>\n";
	print "<td valign='top'>";




	print "<table id='QB_right_sub_table' border='0' align='right'>\n";
	print "<tr><td valign='top' colspan=2>Export to:<br/>" .
			"<label><input type='radio' name='export_action' value='screen' ";
	if ($queryRequest->getExportTo() == 'screen') print "checked='true'";
	print " >Browser</label>&nbsp;";

	print "<label><input type='radio' id='export_action_csv' name='export_action' value='csv' ";
	if ($queryRequest->getExportTo() == 'csv') print "checked='true'";
	print " >CSV File</label>";

	print "<td valign='top' colspan=2>Deleted and archived records:<br/>" .
			"<label><input type='radio' name='query_action' value='Query' ";
	if ($queryRequest->getQueryAction() == 'Query') print "checked='true'";
	print " >Exclude</label>&nbsp;";

	print "<label><input type='radio' name='query_action' value='QueryAll' ";
	if ($queryRequest->getQueryAction() == 'QueryAll') print "checked='true'";
	print " >Include</label></td></tr>\n";




	print "<tr><td><br/>Sort results by:</td> <td><br/>&nbsp;</td> <td><br/>&nbsp;</td> <td><br/>Max Records:</td></tr>\n";
	print "<tr>";
	print "<td><select id='QB_orderby_field' name='QB_orderby_field' style='width: 16em;' onChange='build_query();'>\n";
	print "<option value=''></option>\n";
	if(isset($describeSObject_result)){
		foreach($describeSObject_result->fields as $fields => $field){
			print   " <option value='$field->name'";
			if ($queryRequest->getOrderByField() != null && $field->name == $queryRequest->getOrderByField()) print " selected='selected' ";
			print ">$field->name</option>\n";
		}
	}
	print "</select></td>\n";

	$QB_orderby_sort_options = array(
		'ASC' => 'A to Z',
		'DESC' => 'Z to A'
	);
	
	print "<td><select id='QB_orderby_sort' name='QB_orderby_sort' style='width: 10em;' onChange='build_query();' onkeyup='build_query();'>\n";
	foreach ($QB_orderby_sort_options as $op_key => $op){
		print "<option value='$op_key'";
		if (isset($_POST['QB_orderby_sort']) && $op_key == $_POST['QB_orderby_sort']) print " selected='selected' ";
		print ">$op</option>\n";
	}
	print "</select></td>\n";

	$QB_nulls_options = array(
	'FIRST' => 'Nulls First',
	'LAST' => 'Nulls Last'
	);
	print "<td><select id='QB_nulls' name='QB_nulls' style='width: 10em;' onChange='build_query();' onkeyup='build_query();'>\n";
	foreach ($QB_nulls_options as $op_key => $op){
		print "<option value='$op_key'";
		if ($queryRequest->getOrderByNulls() != null && $op_key == $queryRequest->getOrderByNulls()) print " selected='selected' ";
		print ">$op</option>\n";
	}
	print "</select></td>\n";

	print "<td><input type='text' id='QB_limit_txt' size='10' name='QB_limit_txt' value='" . htmlspecialchars($queryRequest->getLimit() != null ? $queryRequest->getLimit() : null,ENT_QUOTES,'UTF-8') . "' onkeyup='build_query();' /></td>\n";

	print "</tr>\n";
	
	print "</table>\n";
	print "</td></tr>\n";
	
	$filterRowNum = 0;
	foreach($queryRequest->getFilters() as $filter){		
		print "<script>addFilterRow(" . 
		$filterRowNum++ . ", " . 
		"\"" . $filter->getField()     . "\", " . 
		"\"" . $filter->getCompOper()  . "\", " . 
		"\"" . $filter->getValue()     . "\"" .
		");</script>";
	}


	print "<tr><td valign='top' colspan=5><br/>Enter or modify a SOQL query below:\n" .
		"<br/><textarea id='soql_query_textarea' type='text' name='soql_query' cols='107' rows='" . $_SESSION['config']['textareaRows'] . "'  style='overflow: auto; font-family: monospace, courier;'>" . htmlspecialchars($queryRequest->getSoqlQuery(),ENT_QUOTES,'UTF-8') . "</textarea>\n" .
	  "</td></tr>\n";


	print "<tr><td colspan=1><input type='submit' name='querySubmit' value='Query' onclick='return parentChildRelationshipQueryBlocker();' />\n" .
	      "<input type='reset' value='Reset' />\n" .
	      "</td>";
	
	//save and retrieve named queries
	print "<td colspan=4 align='right'>";

	print "&nbsp;Run: " .
		  "<select name='getQr' style='width: 10em;' onChange='document.query_form.submit();'>" . 
	      "<option value='' selected='selected'></option>";
	if(isset($_SESSION['savedQueryRequests'])){
		foreach ($_SESSION['savedQueryRequests'] as $qrName => $qr){
			if($qrName != null) print "<option value='$qrName'>$qrName</option>";
		}
	}
	print "</select>";
	
	
	print "&nbsp;&nbsp;Save as: <input type='text' id='saveQr' name='saveQr' value='" . htmlspecialchars($queryRequest->getName(),ENT_QUOTES,'UTF-8') . "' style='width: 10em;'/>\n";
	
	print "<input type='submit' name='doSaveQr' value='Save' onclick='return doesQueryHaveName();' />\n";
	print "<input type='submit' name='clearAllQr' value='Clear All'/>\n";	
	
	print "&nbsp;&nbsp;" . 
	      "<img onmouseover=\"Tip('Save a query with a name and run it at a later time during your session. Note, if a query is already saved with the same name, the previous one will be overwritten.')\" align='absmiddle' src='images/help16.png'/>";
	
	print "</td></tr></table><p/>\n";
}


function query($soql_query,$query_action,$query_locator = null,$suppressScreenOutput=false){
	try{

		global $mySforceConnection;
		if ($query_action == 'Query') $query_response = $mySforceConnection->query($soql_query);
		if ($query_action == 'QueryAll') $query_response = $mySforceConnection->queryAll($soql_query);
		if ($query_action == 'QueryMore' && isset($query_locator)) $query_response = $mySforceConnection->queryMore($query_locator);

		if (substr_count($soql_query,"count()") && $suppressScreenOutput == false){
			$countString = "Query would return " . $query_response->size . " record";
			$countString .= ($query_response->size == 1) ? "." : "s.";
			show_info($countString);
			$records = $query_response->size;
			include_once('footer.php');
			exit;
		}

		if(isset($query_response->records)){
			$records = $query_response->records;
		} else {
			$records = null;
		}

		$_SESSION['totalQuerySize'] = $query_response->size;

		if(!$query_response->done){
			$_SESSION['queryLocator'] = $query_response->queryLocator;
		} else {
			$_SESSION['queryLocator'] = null;
		}
		
		//correction for documents and attachments with body. issue #176
	    if($query_response->size > 0 && !is_array($records)){
			$records = array($records);
    	}
		
		while(($suppressScreenOutput || $_SESSION['config']['autoRunQueryMore']) && !$query_response->done){
			$query_response = $mySforceConnection->queryMore($query_response->queryLocator);
			
			if(!is_array($query_response->records)){
				$query_response->records = array($query_response->records);
			}
			
			$records = array_merge($records,$query_response->records);
		}
    	
		return $records;

	} catch (Exception $e){
		print "<p><a name='qr'>&nbsp;</a></p>";
		show_error($e->getMessage(),true,true);
	}
}

function getQueryResultHeaders($sobject, $tail=""){	
	if(!isset($headerBufferArray)){
		$headerBufferArray = array();
	}

	if (isset($sobject->Id)){
		$headerBufferArray[] = $tail . "Id";
	}

	if (isset($sobject->fields)){
		foreach($sobject->fields->children() as $field){
			$headerBufferArray[] = $tail . htmlspecialchars($field->getName(),ENT_QUOTES,'UTF-8');
		}
	}

	if(isset($sobject->sobjects)){
		foreach($sobject->sobjects as $sobjects){
			$recurse = getQueryResultHeaders($sobjects, $tail . htmlspecialchars($sobjects->type,ENT_QUOTES,'UTF-8') . ".");
			$headerBufferArray = array_merge($headerBufferArray, $recurse);
		}
	}

	if(isset($sobject->queryResult)){
		if(!is_array($sobject->queryResult)) $sobject->queryResult = array($sobject->queryResult);
		foreach($sobject->queryResult as $qr){
			$headerBufferArray[] = $qr->records[0]->type;			
		}
	}	

	return $headerBufferArray;
}


function getQueryResultRow($sobject, $escapeHtmlChars=true){

	if(!isset($rowBuffer)){
		$rowBuffer = array();
	}
	 
	if (isset($sobject->Id)){
		$rowBuffer[] = $sobject->Id;
	}

	if (isset($sobject->fields)){
		foreach($sobject->fields as $datum){
			$rowBuffer[] = $escapeHtmlChars ? htmlspecialchars($datum,ENT_QUOTES,'UTF-8') : $datum;
		}
	}

	if(isset($sobject->sobjects)){
		foreach($sobject->sobjects as $sobjects){
			$rowBuffer = array_merge($rowBuffer, getQueryResultRow($sobjects,$escapeHtmlChars));
		}
	}
	
	if(isset($sobject->queryResult)){
		$rowBuffer[] = $sobject->queryResult;
	}
	
	return $rowBuffer;
}


function createQueryResultTable($records){
	$table = "<table id='query_results' class='" . getTableClass() . "'>\n";
	
	//call shared recusive function above for header printing
	$table .= "<tr><th></th><th>";
	if($records[0] instanceof SObject){
		$table .= implode("</th><th>", getQueryResultHeaders($records[0]));
	} else{
		$table .= implode("</th><th>", getQueryResultHeaders(new SObject($records[0])));
	}	
	$table .= "</th></tr>\n";
		
	
	$rowNum = 1;
	//Print the remaining rows in the body
	foreach ($records as $record){
		//call shared recusive function above for row printing
		$table .= "<tr><td>" . $rowNum++ . "</td><td>";
		
		if($record instanceof SObject){
			$row = getQueryResultRow($record); 
		} else{
			$row = getQueryResultRow(new SObject($record)); 
		}

		
		for($i = 0; $i < count($row); $i++){				
			if($row[$i] instanceof QueryResult && !is_array($row[$i])) $row[$i] = array($row[$i]);		
			if(isset($row[$i][0]) && $row[$i][0] instanceof QueryResult){
				foreach($row[$i] as $qr){
					$table .= createQueryResultTable($qr->records);	
					if($qr != end($row[$i])) $table .= "</td><td>";
				}
			} else {
				$table .= $row[$i];
			}
					
			if($i+1 != count($row)){
				$table .= "</td><td>";
			}
		}
		
		$table .= "</td></tr>\n";
	}
	
	$table .= "</table>";

	return $table;
}


//If the user selects to display the form on screen, they are routed to this function
function show_query_result($records, $queryTimeElapsed){
	
	//Check if records were returned
	if ($records) {
		try {
			$rowNum = 0;
			print "<a name='qr'></a><div style='clear: both;'><br/><h2>Query Results</h2>\n";
			if(isset($_SESSION['queryLocator']) && !$_SESSION['config']['autoRunQueryMore']){
				preg_match("/-(\d+)/",$_SESSION['queryLocator'],$lastRecord);
				$rowNum = ($lastRecord[1] - count($records) + 1);
				print "<p>Returned records $rowNum - " . $lastRecord[1] . " of ";
			} else if (!$_SESSION['config']['autoRunQueryMore']){
				$rowNum = ($_SESSION['totalQuerySize'] - count($records) + 1);
				print "<p>Returned records $rowNum - " . $_SESSION['totalQuerySize'] . " of ";
			} else {
				$rowNum = 1;
				print "<p>Returned ";
			}
			 
			print $_SESSION['totalQuerySize'] . " total record";
			if ($_SESSION['totalQuerySize'] !== 1) print 's';
			print " in ";
			printf ("%01.3f", $queryTimeElapsed);
			print " seconds:</p>\n";

			if (!$_SESSION['config']['autoRunQueryMore'] && $_SESSION['queryLocator']){
			 print "<p><input type='submit' name='queryMore' id='queryMoreButtonTop' value='More...' /></p>\n";
			}
			
			print addLinksToUiForIds(createQueryResultTable($records));

			if (!$_SESSION['config']['autoRunQueryMore'] && $_SESSION['queryLocator']){
				print "<p><input type='submit' name='queryMore' id='queryMoreButtonBottom' value='More...' /></p>";
			}

			print	"</form></div>\n";
		} catch (Exception $e) {
			print "<p />";
			show_error($e->getMessage(), false, true);
		}
	} else {
		print "<p><a name='qr'>&nbsp;</a></p>";
		show_error("Sorry, no records returned.");
	}
	include_once('footer.php');
}


//Export the above query to a CSV file
function export_query_csv($records,$query_action){
	if ($records) {
		try {
			$csv_file = fopen('php://output','w') or die("Error opening php://output");
			$csv_filename = "export" . date('YmdHis') . ".csv";
			header("Content-Type: application/csv");
			header("Content-Disposition: attachment; filename=$csv_filename");

			//Write first row to CSV and unset variable
			fputcsv($csv_file,getQueryResultHeaders(new SObject($records[0])));

			//Export remaining rows and write to CSV line-by-line
			foreach ($records as $record) {
				fputcsv($csv_file, getQueryResultRow(new SObject($record),false));
			}
			
			fclose($csv_file) or die("Error closing php://output");
			
		} catch (Exception $e) {
			require_once("header.php");
			show_query_form($_POST['soql_query'],'csv',$query_action);
			print "<p />";
			show_error($e->getMessage(),false,true);
		}
	} else {
		require_once("header.php");
		show_query_form($_POST['soql_query'],'csv',$query_action);
		print "<p />";
		show_error("No records returned for CSV output.",false,true);
	}
}

?>
