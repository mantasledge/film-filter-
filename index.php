<?php
//SCC
$peers = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT, array('options' => array('default'=>0)));
$hours = filter_input(INPUT_GET, 'h', FILTER_VALIDATE_INT, array('options' => array('default'=>48)));
$passkey = ("")?: (filter_input(INPUT_GET, 'k', FILTER_SANITIZE_STRING))?: exit("passkey not set!"); 
//passkey is needed (recommend setting this here and never via get request to a remote server)
$cat = filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT, array('options' => array('default'=>7)));

//OMDB
$rating = filter_input(INPUT_GET, 'r', FILTER_VALIDATE_INT | FILTER_VALIDATE_FLOAT, array('options' => array('default'=>5.5))); // accepts a decimal
$votes = filter_input(INPUT_GET, 'v', FILTER_VALIDATE_INT, array('options' => array('default'=>1000)));

// get feed from SCC
$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
$doc = @$dom->load("https://sceneaccess.eu/rss?feed=dl&cat=".$cat."&passkey=".$passkey); 
if(!$doc)exit("error loading SCC! check passkey maybe?");

header('Content-type: application/xml');
$dom->formatOutput = true;
$items = $dom->getElementsByTagName('item');

$remove = array();
$cutOff = time() - $hours * 60 * 60;

foreach ($items as $item){
	$desc = $item->getElementsByTagName('description')->item(0)->nodeValue;
	
	preg_match("/(?<seeds>\d*) seeder.*Added: (?<date>.*)\n/s",$desc,$info);
	
	if($cutOff > strtotime($info['date'])){  //old, no need to search OMDB for this film, it's been up for awhile (user defined time in hours)  
		$remove[] = $item;
		continue;
	}
	if($peers >= $info['seeds']){ //No seeders, nuked, terribad film
		$remove[] = $item;
		continue;
	}
}

foreach ($remove as $domElement){
	$domElement->parentNode->removeChild($domElement); // clean the list
}
$remove = array();
$items = $dom->getElementsByTagName('item');

//OMDB 
foreach ($items as $item){
	$title = $item->getElementsByTagName('title')->item(0)->nodeValue;
	$arr = explode(".",$title);
	$year = preg_grep('/^\d{4}$/',$arr); //attempt to find year of release (needed by OMDB)
	if(empty($year)){
		$remove[] = $item;
		continue;		
	};
	$key = (count($year)>1?endKey($year):key($year)); // year film may have been released
	if($year[$key] <= 2012){ // crude attempt to avoid remastered/classic releases in favour of potential remakes (needs work)
		$remove[] = $item;
		continue;		
	}
	
	//contitions passed, request data from OMDB
	$name = implode(" ", array_slice($arr,0,$key));
	$data = http_build_query(array("y"=>$year[$key],"t"=>$name), "", "&");
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://www.omdbapi.com/?".$data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$omdb = curl_exec($ch);
	@curl_close($ch);
	
	//error checks
	if($omdb === false){
		$remove[] = $item;
		continue;
	}
	
	$json = json_decode($omdb);
	
	if($json === NULL || json_last_error() != 0){
		$remove[] = $item;
		continue;
	}
	if($json->Response == "False"){
		$remove[] = $item;
		continue;
	}
	
	//conditions
	$imdbRating = (property_exists($json,"imdbRating")?$json->imdbRating:0);
	
	if($imdbRating < $rating || $imdbRating == "N/A"){ //rating 0 - 10
		$remove[] = $item;
		continue;
	}else{
		if($votes > str_replace(",","",$json->imdbVotes)){ //votes 0 - 7.1billion
			$remove[] = $item;
			continue;
		}
	}
}
foreach ($remove as $domElement){
	$domElement->parentNode->removeChild($domElement); 
}
echo $dom->saveXML(); //echo clean data (if any)

function endKey($array){
	end($array);
	return key($array);
}
?>
