<?php
/**
 * Auto Deploy
 */

define('BASE_DIR', __DIR__ . '/../');

$config = file_exists(BASE_DIR . 'config.yml') ? json_decode(json_encode(yaml_parse_file(BASE_DIR . 'config.yml'))) : null;
if (!$config) {
    send_response(501);
}

error_reporting(E_ALL);

register_shutdown_function(function () use ($config) {
    $error = error_get_last();
    if (!is_null($error)) {
        send_response(500, $config->debug ? ['error' => $error] : null);
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
    main($config);
} catch (Throwable $e) {
    send_response(500, $config->debug ? [
        'error' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace()
        ]
    ] : false);
}

/**
 * main
 *
 * @param mixed $config
 * @return void
 */
function main($config)
{
    $headers = getallheaders();
    $userAgent = $headers['User-Agent'];

    $data = json_decode(file_get_contents('php://input'));
    if (!isset($data->repository->name) || !isset($data->actor->account_id) || !isset($data->push->changes[0])) {
        send_response(400, $config->debug ? ['error' => 'Invalid payload'] : null);
    }

    $repo = $data->repository->name;
    $accountId = $data->actor->account_id;
    $change = $data->push->changes[0];

    $repoConfig = array_filter($config->repos, function ($it) use ($repo) {
        return $it->name === $repo;
    })[0];
    if (!isset($repoConfig)) {
        send_response(401, $config->debug ? ['error' => 'Unknown repository'] : null);
    }
    if (empty($repoConfig->app_dir) || empty($repoConfig->branch_pattern) || !file_exists($repoConfig->app_dir)) {
        send_response(500, $config->debug ? ['error' => 'Missing or invalid configuration'] : null);
    }

    if (isset($repoConfig->token)) {
        $token = isset($_GET['token']) ? $_GET['token'] : null;
        if ($token !== $repoConfig->token) {
            send_response(401, $config->debug ? ['error' => 'Invalid token'] : null);
        }
    }
    if (!empty($repoConfig->account_id) && !in_array($accountId, $repoConfig->account_id)) {
        send_response(204);
    }

    $type = $change->new->type;
    $release = $change->new->name;

    if ($type !== 'branch' || empty($release) || !preg_match($repoConfig->branch_pattern, $release)) {
        send_response(204);
    }

    $output = [
        "userAgent" => $userAgent,
        "repository" => $repo,
        "dir" => $repoConfig->app_dir,
        "release" => $release,
        "actor" => $data->actor->nickname
    ];

    // TODO: move these to the config.yml
    $command = implode(' && ', [
        'whoami',
        "cd $repoConfig->app_dir",
        'echo $PWD',
        'git fetch',
        "git checkout $release",
        "git pull",
        'git status',
        'export COMPOSER_HOME=/tmp',
        'composer install --no-interaction --no-dev --prefer-dist 2>&1'
    ]);
    $result = shell_exec($command);

    $output['commands'][] = [
        'command' => $command,
        'result' => $result
    ];

    // log
    if ($repoConfig->log_dir) {
        $logDir = substr($repoConfig->log_dir, 0, 1) === '/' ? $repoConfig->log_dir : BASE_DIR . $repoConfig->log_dir;
        if (!file_exists($logDir)) {
            mkdir($logDir, 0775);
        }
        $logDir = realpath($logDir);
        $nowUtc = gmdate('Y-m-d_His');
        $logFile = sprintf('%s_%s_%s.log', $nowUtc, $repo, $release);
        // sanitize file name
        $logFile = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '_', $logFile);
        $logFile = preg_replace("([\.]{2,})", '_', $logFile);
        $filePath = $logDir . DIRECTORY_SEPARATOR . $logFile;
        file_put_contents($filePath, '$ ' . $command . PHP_EOL . PHP_EOL . $result);
        $output['logFile'] = $filePath;
    }

    send_response(200, $output);
}

/**
 * @param int $code
 * @param mixed $data
 */
function send_response($code, $data = null)
{
    http_response_code($code);
    if (!is_null($data)) {
        echo json_encode($data);
    }
    error_clear_last();
    exit;
}

function dd($value)
{
    echo json_encode($value);
    error_clear_last();
    exit;
}