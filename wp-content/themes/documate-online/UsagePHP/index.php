<?php
	require_once('OAuth.php');

	$oAuth = new OAuth();
?>

<!DOCTYPE html>

<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>Тестування автентифікації</title>
</head>
<body>
    <input id="auth" type="button" value="Authenticate" onclick="location.href='<?php echo $oAuth->getAuthURI(); ?>'"/>
</body>
</html>
