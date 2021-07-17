<?php

namespace App\Logic\StreamingServer;

use React\EventLoop\Factory;
use React\Socket\Server as Socket;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\Logic\StreamingServer\VideoStreamLogic;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// config
$address = '0.0.0.0';
$port = 6001;


// Create a new Event Loop. Factory chooses the best loop implementation.
$loop = Factory::create();

$msgComponent = new VideoStreamLogic($loop);
$wsServer = new WsServer($msgComponent);
$httpServer = new HttpServer($wsServer);
$socketServer = new Socket($address . ':' . $port, $loop);
$server = new IoServer($httpServer, $socketServer, $loop);

$server->run();
