<?php
// This is the code that must be ran server-side to interact with FiatDex Marketplace
// This handles all the database code using SQLite v3 database

$database_fullpath = "../market.db"; // Database is stored above the webroot
date_default_timezone_set("UTC");

// Handle the GET requests first
if(isset($_GET["request"])){
	$request = $_GET["request"];
	if($request == "get_offers"){
		$market = $_GET["market"];
		$page = (int)$_GET["offer_page"];
		if($page < 0){
			exit; // Should be no negative numbers
		}
		$offset = $page * 10;
		$market_type = 0;
		if($market == "sell"){
			$market_type = 1;
		}
		if($offset > 10000){
			$offset = 10000; // Unlikely to have more than 10,000 offers
		}

		// Now check database for matching offers
		$all_offers = array();
		if(file_exists($database_fullpath) != false){
			// If page is 0, delete old offers (> 7 days)
			$db = connectDatabase($database_fullpath);
			if($page == 0){
				$statement = $db->prepare("Delete From OFFERS Where offer_time < :time;"); // Prepare the statement
				$statement->bindValue(":time",time() - 86400*7); // Anything older than 7 days gets deleted
				$statement->execute();
				$statement->closeCursor();
			}

			// Now go through the list of offers to load
			$statement = $db->prepare("Select offer_id, fiat_symbol, price, quantity, min_quantity, payment_method, contact_method, contact_address 
			From OFFERS Where offer_type = :type Order By offer_time DESC Limit 10 Offset ".$offset.";"); //Prepare the statement
			$statement->bindValue(":type",$market_type);
			$statement->execute(); // Execute the statement and get the object result
			$ocount = 0;
			while($offerdata = $statement->fetch()){
				// Load all the offer data from the database
				// Will be presorted by time, most recent first
				$all_offers[$ocount]["offer_id"] = $offerdata["offer_id"];
				$all_offers[$ocount]["fiat"] = $offerdata["fiat_symbol"];
				$all_offers[$ocount]["price"] = $offerdata["price"];
				$all_offers[$ocount]["quant"] = $offerdata["quantity"];
				$all_offers[$ocount]["min_quant"] = $offerdata["min_quantity"];
				$all_offers[$ocount]["pay"] = $offerdata["payment_method"];
				$all_offers[$ocount]["contactm"] = $offerdata["contact_method"];
				$all_offers[$ocount]["contacta"] = $offerdata["contact_address"];
				$ocount++;
			}
			$statement->closeCursor();
			$db = null;
		}

		echo json_encode($all_offers);
	}else{
		exit; // Should not have any other type of get requests
	}
}else if(isset($_POST["request"])){
	// Post data has more varied responses
	$request = $_POST["request"];
	if($request == "add_offer"){
		// First validate the input from the user
		$important = $_POST["honeypot"]; // A honeypot for bots who try to auto submit
		if(strlen($important) > 0){exit;}

		$order_type = (int)$_POST["type"];
		if($order_type != 1 && $order_type != 0){exit;}

		$fiat_symbol = $_POST["fiat"];
		if(strlen($fiat_symbol) > 5){exit;}
		if(validateSymbol($fiat_symbol) == false){exit;}
		$fiat_symbol = sanitizeText(strtoupper($fiat_symbol)); // Remove potential injection code

		$price = $_POST["price"];
		if(strlen($price) > 20){exit;}
		if(validateSymbol($price) == false){exit;}
		$price = sanitizeText(strtoupper($price)); // Remove potential injection code

		$quantity = $_POST["quant"];
		if(strlen($quantity) > 20){exit;}
		if(validateText($quantity) == false){exit;}
		$quantity = sanitizeText(strtoupper($quantity)); // Remove potential injection code

		$min_quantity = $_POST["min_quant"];
		if(strlen($min_quantity) > 20){exit;}
		if(validateText($min_quantity) == false){exit;}
		$min_quantity = sanitizeText(strtoupper($min_quantity)); // Remove potential injection code

		$payment_method = $_POST["pay_method"];
		if(strlen($payment_method) > 20){exit;}
		if(validateText($payment_method) == false){exit;}
		$payment_method = sanitizeText($payment_method); // Remove potential injection code

		$contact_method = $_POST["con_method"];
		if(strlen($contact_method) > 20){exit;}
		if(validateText($contact_method) == false){exit;}
		$contact_method = sanitizeText($contact_method); // Remove potential injection code

		$contact_address = $_POST["con_add"];
		if(strlen($contact_address) > 200){exit;}
		if(validateText($contact_address) == false){exit;}
		$contact_address = sanitizeText($contact_address); // Remove potential injection code

		$makeindex = false;
		if(file_exists($database_fullpath) == false){
			// Doesn't exist yet, creating schema
			$makeindex = true;
			$db = connectDatabase($database_fullpath);
			$myquery = "Create Table OFFERS(oindex INTEGER PRIMARY KEY ASC,
				offer_id TEXT,
				offer_secret TEXT,
				offer_type INTEGER,
				offer_time INTEGER,
				fiat_symbol TEXT,
				price TEXT,
				quantity TEXT,
				min_quantity TEXT,
				payment_method TEXT,
				contact_method TEXT,
				contact_address TEXT);";
			$db->exec($myquery); // Create the schema
		}else{
			$db = connectDatabase($database_fullpath);
		}

		$ctime = time(); // Current time in seconds since Unix epoch
		$offer_id = bin2hex(openssl_random_pseudo_bytes(12)); // Create an offer_ID ( probably unique)
		$offer_secret = bin2hex(openssl_random_pseudo_bytes(12)); // Create a secret for the offer, in case user wants to delete

		// Now create the new offer
		$myquery = 'Insert Into OFFERS (offer_id, offer_secret, offer_type, offer_time, fiat_symbol, price, quantity, min_quantity, payment_method, contact_method, contact_address) 
		Values (:my_id, :my_secret, :my_type, :my_time, :my_fiat, :my_price, :my_quant, :my_min_quant, :my_pay, :my_con_met, :my_con_add);';
		$statement = $db->prepare($myquery);
		$statement->bindValue(":my_id",$offer_id);
		$statement->bindValue(":my_secret",$offer_secret);
		$statement->bindValue(":my_type",$order_type);
		$statement->bindValue(":my_time",$ctime);
		$statement->bindValue(":my_fiat",$fiat_symbol);
		$statement->bindValue(":my_price",$price);
		$statement->bindValue(":my_quant",$quantity);
		$statement->bindValue(":my_min_quant",$min_quantity);
		$statement->bindValue(":my_pay",$payment_method);
		$statement->bindValue(":my_con_met",$contact_method);
		$statement->bindValue(":my_con_add",$contact_address);
		$statement->execute(); //Execute the statement
		$statement->closeCursor();

		if($makeindex == true){
			$db->exec("Create Index index1 On OFFERS(offer_id)");
		}
	    $db = null;

	    echo $offer_secret; // Now return the secret that the user can use to remove the order

	}else if($request == "remove_offer"){

		$order_id = $_POST["id"];
		if(strlen($order_id) > 30){exit;}
		if(validateSymbol($order_id) == false){exit;}

		$order_secret = $_POST["code"];
		if(strlen($order_secret) > 30){exit;}
		if(validateText($order_secret) == false){exit;}

		// Now remove this order from the database if present
		if(file_exists($database_fullpath) != false){
			// If page is 0, delete old offers (> 7 days)
			$db = connectDatabase($database_fullpath);
			$statement = $db->prepare("Delete From OFFERS Where offer_id = :my_id And offer_secret = :my_secret;"); // Prepare the statement
			$statement->bindValue(":my_id",$order_id); 
			$statement->bindValue(":my_secret",$order_secret); 
			$statement->execute();
			$lines_changed = $statement->rowCount();
			$statement->closeCursor();
			$db = null;

			if($lines_changed > 0){
				echo "Success";
				exit;
			}
		}

		echo "Failure";

	}else{
		exit;
	}
}else{
	exit;
}

// SQLite database manager
function connectDatabase($filename){
	$tdb = new PDO("sqlite:".$filename);
	$tdb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); // Make errors in the database crash the program, what we want actually
	$tdb->setAttribute(PDO::ATTR_EMULATE_PREPARES,false); // It forces the driver to use SQLite prepare and not its own
	$tdb->exec("PRAGMA busy_timeout = 5000"); // Make sure it actually does wait 5 seconds
	return $tdb;
}

function validateSymbol($txt){
	if(strlen(trim($txt)) == 0){return false;}
	$result = strpos($txt," ");
	if($result !== false){return false;} // No spaces allowed
	$result = strpos($txt,"<");
	if($result !== false){return false;} // No open html code
	$result = strpos($txt,">");
	if($result !== false){return false;} // No close html code
	return true;
}

function validateText($txt){
	if(strlen(trim($txt)) == 0){return false;}
	$result = strpos($txt,"<");
	if($result !== false){return false;} // No open html code
	$result = strpos($txt,">");
	if($result !== false){return false;} // No close html code
	return true;
}

function sanitizeText($txt){
	// Returns a javascript safe text that is free from potential injection code
	return htmlspecialchars($txt,ENT_QUOTES,'UTF-8');
}
?>