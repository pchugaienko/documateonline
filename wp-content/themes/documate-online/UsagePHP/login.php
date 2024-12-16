<?php
	require_once('OAuth.php');
?>

<!DOCTYPE html>

<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>Користувача автентифіковано</title>
</head>
<body>
<?php
	$oAuth = new OAuth();

	$code = $_GET['code'];
	$authCode = $oAuth->getAuthorizationCode(
		$code
	);
	$userInfo = $oAuth->getUserInfo(
		$authCode->user_id,
		$authCode->access_token
	);

	echo 'Інформація про користувача:<br>';
	echo 'SubjCN:'.$userInfo->subjectcn.'<br>';
	echo 'EDRPOU:'.$userInfo->edrpoucode.'<br>';
	echo 'DRFO:'.$userInfo->drfocode.'<br>';
	echo 'IssuerCN:'.$userInfo->issuercn.'<br>';
	echo 'Serial:'.$userInfo->serial.'<br>';
?>
</body>
</html>
