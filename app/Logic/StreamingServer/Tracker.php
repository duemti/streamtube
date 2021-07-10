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

	// array -> Downloaded Torrent file.
	private $torrent;

	public function	__construct(LoopInterface $loop, WebSocketConnection $client)
	{
		$this->loop = $loop;
		$this->client = $client;
	}

	/**
	 * Tracker server uses either HTTP or UDP protocols.
	 */
	public function	interogate(array $metainfo): PromiseInterface
	{
		//$uri = '174.138.32.158:1234';
		return (0 === strncmp("udp://", $this->uri, 5))
			? $this->interogateUdp($metainfo)
			: $this->interogateHttp($metainfo);
	}

	/**
	 * Donwload torrent file using Http Async.
	 */
	private function	interogateHttp(array $metainfo): PromiseInterface
	{
		$deferred = new Deferred();
		$httpClient = new Browser($this->loop);
		$torrent = &$this->torrent;
		$webclient = &$this->client;
		$uri = $this->uri
			. "?info_hash=" . urlencode($metainfo['info_hash'])
			. "&peer_id=" . urlencode($metainfo['peer_id'])
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

	/**
	 * Making the request to Tracker Server
	 */
	private function	interogateUdp(array $metainfo): PromiseInterface
	{
		$deferred = new Deferred();
		$deferred->reject("udp not working.");

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
		return $deferred->promise();
	}
}