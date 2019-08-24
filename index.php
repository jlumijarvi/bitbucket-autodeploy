<?php
/**
 * Auto Deploy
 */

error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if (!is_null($error)) {
        http_response_code(500);
        echo json_encode($error);
    }
});

header('Content-Type: application/json');

// get headers with nginx
if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

try {
    loadEnv();
    main();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTrace()
    ]);
}

/**
 * Load environment variables
 */
function loadEnv()
{
    $lines = explode(PHP_EOL, file_get_contents('.env'));
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || preg_match('/^#/', $line)) {
            continue;
        }
        list ($var, $val) = explode('=', $line, 2);
        if (!isset($val)) {
            exit;
        }
        putenv("$var=$val");
    }
}

/**
 * main
 *
 * @return void
 */
function main()
{
    $headers = getallheaders();
    $userAgent = $headers['User-Agent'];

    $token = isset($_GET['token']) ? $_GET['token'] : null;

    if ($token !== getenv('TOKEN')) {
        http_response_code(403);
        exit;
    }

    $appDir = getenv('APP_DIR');
    if (empty($appDir) || !file_exists($appDir)) {
        http_response_code(500);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'));
    if (!isset($data->push->changes[0])) {
        http_response_code(400);
        exit;
    }

    $change = $data->push->changes[0];
    $type = $change->new->type;
    $release = $change->new->name;

    if ($type !== 'branch' || empty($release) || !preg_match(getenv('BRANCH_PATTERN'), $release)) {
        http_response_code(204);
        exit;
    }

    $output = [
        "userAgent" => $userAgent,
        "release" => $release,
    ];

    $command = implode(' && ', [
        'whoami',
        "cd $appDir",
        'echo $PWD',
        'git fetch',
        "git checkout $release",
        'git status',
        'export COMPOSER_HOME=/tmp',
        'composer install --no-interaction --no-dev --prefer-dist 2>&1'
    ]);
    $result = shell_exec($command);

    $output['commands'] = [
        'command' => $command,
        'result' => $result
    ];

    // log
    $logDir = getenv('LOG_DIR');
    if ($logDir) {
        if (!file_exists($logDir)) {
            mkdir($logDir);
        }
        $logDir = realpath($logDir);
        $nowUtc = gmdate('Y-m-d_His');
        $logFile = sprintf('%s_%s.log', $nowUtc, $release);
        // sanitize file name
        $logFile = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '_', $logFile);
        $logFile = preg_replace("([\.]{2,})", '_', $logFile);
        $filePath = $logDir . DIRECTORY_SEPARATOR . $logFile;
        file_put_contents($filePath, '$ ' . $command . PHP_EOL . PHP_EOL . $result);
        $output['logFile'] = $filePath;
    }

    http_response_code(200);
    echo json_encode($output);
}
