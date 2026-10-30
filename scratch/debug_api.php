<?php
function testCall($action, $params = [], $method = 'GET') {
    $script = __DIR__ . '/tmp_test.php';
    $code = '<?php' . "\n";
    $code .= 'session_start();' . "\n";
    $code .= '$_SESSION["user_id"] = 1;' . "\n";
    $code .= '$_SESSION["user"] = ["user_id" => 1, "role_code" => "PLATFORM_ADMIN"];' . "\n";
    if ($method === 'GET') {
        $code .= '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n";
        $code .= '$_GET = ' . var_export(array_merge(['action' => $action], $params), true) . ';' . "\n";
        $code .= '$_POST = [];' . "\n";
    } else {
        $code .= '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n";
        $code .= '$_POST = ' . var_export(array_merge(['action' => $action], $params), true) . ';' . "\n";
        $code .= '$_GET = [];' . "\n";
    }
    $code .= '$_REQUEST = array_merge($_GET, $_POST);' . "\n";
    $code .= 'ob_start();' . "\n";
    $code .= 'require ' . var_export(__DIR__ . '/../api/quantity_reco_api.php', true) . ';' . "\n";
    $code .= '$out = ob_get_clean();' . "\n";
    $code .= 'echo $out;' . "\n";

    file_put_contents($script, $code);
    $output = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    echo "Output for $action:\n";
    echo $output . "\n";
}

testCall('get_cost_centres', ['plant_id' => 35]);
testCall('get_reco', ['plant_id' => 35, 'period_id' => 1]);
