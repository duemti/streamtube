<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Ratchet\ConnectionInterface as WebSocketConnection;
use React\EventLoop\LoopInterface;
use React\Datagram\Factory as UdpFactory;
use React\Datagram\Socket as UdpSocket;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Rhilip\Bencode\Bencode;


class Tracker
{
	private $port = 6003;
	private $loop;
	private $client;
	private $info_hash;
	private $peer_id;

	// array -> Downloaded Torrent file.
	private $torrent;

	public function	__construct(
		LoopInterface $loop,
		WebSocketConnection $client,
		string $uri,
		string $info_hash,
		string $peer_id
	) {
		$this->loop = $loop;
		$this->client = $client;
//$uri = '174.138.32.158:1234';
		$this->uri = $uri;
		$this->info_hash = $info_hash;
		$this->peer_id = $peer_id;
	}

	/**
	 * Tracker server uses either HTTP or UDP protocols.
	 */
	public function	interogate(): PromiseInterface
	{
		return (0 === strncmp("udp://", $this->uri, 5))
			? $this->interogateUdp()
			: $this->interogateHttp();
	}

	/**
	 * Donwload torrent file using Http Async.
	 */
	public function	interogateHttp(): PromiseInterface
	{
		$deferred = new Deferred();
		$httpClient = new Browser($this->loop);
		$torrent = &$this->torrent;
		$webclient = &$this->client;
		$uri = $this->uri
			. "?info_hash=" . urlencode($this->info_hash)
			. "&peer_id=" . urlencode($this->peer_id)
			. "&port=" . $this->port;

		echo "Interogating Tracker through HTTP...", PHP_EOL;
		$httpClient->get($uri)->then(
			function (ResponseInterface $response) use ($torrent, $webclient, $deferred)
			{
				$torrent = Bencode::decode((string)$response->getBody());
				echo "Got Response from Tracker!", PHP_EOL;
				$deferred->resolve($torrent);
			},
			function (Exception $error)
			{
				$msg = "HTTP failed to connect to tracker because: " . $error->getMessage() . PHP_EOL;
				echo $msg;
				$client->send($msg);
				$deferred->reject($error);
			}
		);
		return $deferred->promise();
	}

	public function	interogateUdp()
	{
		// Making the request to Tracker Server
		// The request URL
		//
		/*$uri = $this->metainfo['announce'];/*
			"?info_hash=" . urlencode($this->info_hash) .
			"&peer_id=" . urlencode($this->peer_id) .
			"&port=" . $this->port;
		 */
			
		$loop = $this->loop;
		$client = $this->client;
		$connector = new UdpFactory($this->loop, null, array(
			'bindto' => '0:' . $this->port
		));
		$connector->createClient($this->uri)->then(

			function (UdpSocket $tracker) use ($client, $loop)
			{
				// Say hello.
				$connection_id = pack('J', 0x41727101980);
				$action = pack('N', 0);
				$transaction_id = random_bytes(4);
				$connectPacket = $connection_id.$action.$transaction_id;

echo unpack('H*', $connectPacket)[1], PHP_EOL;

				$tracker->send($connectPacket);
				// Try contacting tracker every 15 seconds.
				$retries = 4;
				$timer = $loop->addPeriodicTimer(5.0, function ($timer)
					use (&$tracker, $connectPacket, &$retries, $loop)
				{
					if ($retries-- > 0)
					{
						echo "Retrying to connect to tracker server...", PHP_EOL;
						$tracker->send($connectPacket);
					} else {
						echo "tracker server is not responding.", PHP_EOL;
						$loop->cancelTimer($timer);
					}
				});

				$client->send("Connected to tracker!");
				echo "Connected to tracker!", PHP_EOL;
				// Get the data.
				$tracker->on('message', function ($data, $serverAddr, $tracker)
					use ($timer, $loop, $client)
				{
					$loop->cancelTimer($timer);
					$client->send("Received: " . $data);
					echo "receiving data from tracker...".PHP_EOL;
					echo $data, PHP_EOL;
				});

				// Error.
				$tracker->on('error', function ($error, $tracker)
					use ($client)
				{
					$client->send("error.");
					echo 'error: ' . $error->getMessage() . PHP_EOL;
				});

				// Event close.
				$tracker->on('end', function ($error, $tracker)
					use ($client)
				{
					$client->send("Closing...");
					echo 'closing... '. PHP_EOL;
				});
			},
			function (Exception $error) use ($client) {
				$msg = "failed to connect to tracker because: " . $error->getMessage() . PHP_EOL;
				echo $msg;
				$client->send($msg);
			}
		);

		//file_put_contents($this->filePath . '.meta', serialize($response));
		//$response = unserialize(file_get_contents($this->filePath . '.meta'));
		//$this->trackerResponse = $response;
		//return $trackerResponse;
	}
}
