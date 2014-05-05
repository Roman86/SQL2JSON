<?php
/**
 * Created by: Roman Kozodoy
 * Date: 06.04.14
 * Time: 21:34
 */

require "TArrayDataSet.php";

try{
	// GET DATA
	$data = array(
		"fields" => array("class", "classdescr", "name", "food", "weight", "size", "anger")
		,"records" => array(
			array("dinosaur", "Long time ago", "t-rex", "other dinosaurs", "wow-wow", "huge", "very angry")
			, array("dinosaur", "Long time ago (once more) - doesn't matter", "brachiosaurus", "trees", "heavy", "very high", "nope")
			, array("dinosaur", "Long long time ago... grouped fields are taken from first record", "compsognathus", "offal", "light", "small", "yes")
			, array("dog", "man's friend", "retriever", "everything", "mid-heavy", "medium", "nope")
			, array("dog", "man's friend", "chihuahua", "smaller dogs", "light", "tiny", "dangerous")
		)
	);
	// next step is CREATING OBJECT
	$ds = new TArrayDataSet();
	// SET DATA
	$ds->setData($data);
	// GET JSON ARRAY
	$json = $ds->toJson("animals:class(class,classdescr)/members:name*(food,weight,size,anger)", true);

	// You're done! Next steps are optional:
	// debug it
	print_r($json);
	// and finally you can encode it and show
	echo json_encode($json);
}catch(Exception $e){
	echo ($e->getMessage());
}