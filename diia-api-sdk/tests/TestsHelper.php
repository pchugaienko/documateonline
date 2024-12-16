<?php
namespace Tests;

use DiiaAPI;

class TestsHelper
{
    public static function getInstanceOfDiiaAPI()
    {
        $environment = 'test'; // Or 'prod', depending on the environment
        $acquirerToken = 'chugaienko_test_token_trt289';
        $authAcquirerToken = 'YWNxdWlyZXJfOTg0OmNodWdhaWVua29fdGVzdF90b2tlbl90cnQyODk='; // If necessary

        $diiaAPI = new DiiaAPI($environment, $acquirerToken, $authAcquirerToken);
        return $diiaAPI;
    }
}
