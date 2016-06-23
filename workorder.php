<?php
	require_once('db.inc.php');
	require_once('facilities.inc.php');

	$subheader=__("Data Center Operations Work Order Builder");
	$error = '';
	$result = 0;
	
	if(!isset($_COOKIE["workOrder"]) || (isset($_COOKIE["workOrder"]) && $_COOKIE["workOrder"]=="" )){
		header("Location: ".redirect());
		exit;
	}

	$devList=array();
	$woList=json_decode($_COOKIE["workOrder"]);
	foreach($woList as $woDev){
		$dev=new Device();
		$dev->DeviceID=$woDev;
		if($dev->GetDevice()){
			$devList[]=$dev;
		}
	}

	if (isset($_POST['action']) && $_POST['action'] == 'Send'){

		$_REQUEST['deviceid'] = 'wo';
		$_REQUEST['temp'] = '1';

		include 'export_port_connections.php';

		require_once( 'swiftmailer/swift_required.php' );

		// If any port other than 25 is specified, assume encryption and authentication
		if($config->ParameterArray['SMTPPort']!= 25){
			$transport=Swift_SmtpTransport::newInstance()
				->setHost($config->ParameterArray['SMTPServer'])
				->setPort($config->ParameterArray['SMTPPort'])
				->setEncryption('ssl')
				->setUsername($config->ParameterArray['SMTPUser'])
				->setPassword($config->ParameterArray['SMTPPassword']);
		}else{
			$transport=Swift_SmtpTransport::newInstance()
				->setHost($config->ParameterArray['SMTPServer'])
				->setPort($config->ParameterArray['SMTPPort']);
		}

		$mailer = Swift_Mailer::newInstance($transport);
		$message = Swift_Message::NewInstance();

		if ( $_REQUEST["deviceid"] == "wo" ) {
			$message->setSubject( __("openDCIM-workorder-".date( "YmdHis" )."-connections") );
		} else {
			$message->setSubject( __($dev->DeviceID."-connections") );
		}

		// Set from address
		try{		
			$message->setFrom($config->ParameterArray['MailFromAddr']);
		}catch(Swift_RfcComplianceException $e){
			$error.=__("MailFrom").": <span class=\"errmsg\">".$e->getMessage()."</span><br>\n";
		}

		// Add data center team to the list of recipients
		try{		
			$message->addTo($config->ParameterArray['FacMgrMail']);
		}catch(Swift_RfcComplianceException $e){
			$error.=__("Facility Manager email address").": <span class=\"errmsg\">".$e->getMessage()."</span><br>\n";
		}

		$logo=getcwd().'/images/'.$config->ParameterArray["PDFLogoFile"];
		$logo=$message->embed(Swift_Image::fromPath($logo)->setFilename('logo.png'));

		$htmlMessage = sprintf( "<!doctype html><html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"><title>Device Port Connections</title></head><body><div id=\"header\" style=\"padding: 5px 0;background: %s;\"><center><img src=\"%s\"></center></div><div class=\"page\">\n", $config->ParameterArray["HeaderColor"], $logo );
		
		$htmlMessage .= sprintf("<h3>Work Order %s</h3><p>UID: %s</p><p>Name: %s, %s</p><p>%s %s has requested this work order. Details are attached to this message.</p>",date( "YmdHis" ),$person->UserID,$person->LastName,$person->FirstName,$person->FirstName,$person->LastName);
		
		$attachment = Swift_Attachment::fromPath($tmpName,"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
		if ( $_REQUEST["deviceid"] == "wo" ) {
			$attachment->setFilename("openDCIM-workorder-".date( "YmdHis" )."-connections.xlsx");
		} else {
			$attachment->setFilename("openDCIM-dev" . $dev->DeviceID . "-connections.xlsx");
		}
		
		$message->attach($attachment);
		$message->setBody($htmlMessage,'text/html');

		try {
			$result = $mailer->send( $message );
		} catch( Swift_RfcComplianceException $e) {
			$error .= "Send: " . $e->getMessage() . "<br>\n";
		} catch( Swift_TransportException $e) {
			$error .= "Server: <span class=\"errmsg\">" . $e->getMessage() . "</span><br>\n";
		}
	}
?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  
  <title>openDCIM Data Center Inventory</title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css" />
  <![endif]-->
  
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
  <script type="text/javascript" src="scripts/jquery.cookie.js"></script>
<script>
	$(document).ready(function(){
		$('#clear').click(function(){
			$.removeCookie('workOrder');
			location.href="index.php";
		});
	});
</script>
</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page index">
<?php
	include( 'sidebar.inc.php' );
?>
<div class="main">

<?php
	if($error!=""){echo '<fieldset class="exception border error"><legend>Errors</legend>'.$error.'</fieldset>';}
	else if($result == 1) {echo '<h3 id="messages">Work Order Sent</h3>';}
?>
<div class="center"><div>
<!-- CONTENT GOES HERE -->
<?php
	echo '<form name="orderform" id="orderform" action="',$_SERVER["PHP_SELF"],'" method="POST">';
	print "<h2>".__("Work Order Contents")."</h2>
<div class=\"table\">
	<div><div>".__("Cabinet")."</div><div>".__("Position")."</div><div>".__("Label")."</div><div>".__("Image")."</div></div>\n";
	
	foreach($devList as $dev){
		// including the $cab and $devTempl in here so it gets reset each time and there 
		// is no chance for phantom data
		$cab=new Cabinet();
		if($dev->ParentDevice>0){
			$pdev=new Device();
			$pdev->DeviceID=$dev->GetRootDeviceID();
			$pdev->GetDevice();
			$cab->CabinetID=$pdev->Cabinet;
		}else{
			$cab->CabinetID=$dev->Cabinet;
		}
		$cab->GetCabinet();
		
		$devTmpl=new DeviceTemplate();
		$devTmpl->TemplateID=$dev->TemplateID;
		$devTmpl->GetTemplateByID();

		$position=($dev->Height==1)?$dev->Position:$dev->Position."-".($dev->Position+$dev->Height-1);

		print "<div><div>$cab->Location</div><div>$position</div><div>$dev->Label</div><div>".$dev->GetDevicePicture('','','nolinks')."</div></div>\n";
	}
	
	print '</div>
<a href="export_port_connections.php?deviceid=wo"><button type="button">'.__("Export Connections").'</button></a>
<button type="submit" name="action" value="Send">'.__("Send Connections to Data Center Team").'</button></a>';
?>

<button type="button" id="clear"><?php print __("Clear"); ?></button>
</form>
</div></div>
</div><!-- END div.main -->
</div><!-- END div.page -->
</body>
</html>
