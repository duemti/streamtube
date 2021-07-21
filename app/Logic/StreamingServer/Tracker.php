<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Ratchet\ConnectionInterface as WebSocketConnection;
use React\EventLoop\Loop;
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
	private $client;

	// array -> Downloaded Torrent file.
	private $torrent;

	public function	__construct(WebSocketConnection $client)
	{
		$this->client = $client;
	}

	/**
	 * Tracker server uses either HTTP or UDP protocols.
	 */
	public function	interogate(array $metainfo): PromiseInterface
	{
		//$uri = '174.138.32.158:1234';
		return (0 === strncmp("udp://", $metainfo['announce'], 5))
			? $this->interogateUdp($metainfo)
			: $this->interogateHttp($metainfo);
	}

	/**
	 * Donwload torrent file using Http Async.
	 * TODO: add retry logic.
	 */
	private function	interogateHttp(array $metainfo): PromiseInterface
	{
		$deferred = new Deferred();
		$browser = new Browser();
		$uri = $metainfo['announce']
			. "?info_hash=" . urlencode($metainfo['info_hash'])
			. "&peer_id=" . urlencode($metainfo['peer_id'])
			. "&port=" . $this->port;

		echo "Interogating Tracker through HTTP...", PHP_EOL;
		$browser->withTimeout(10.0);
		$browser->get($uri)->then(
			function (ResponseInterface $response) use ($deferred)
			{
				echo "Got Response from Tracker!", PHP_EOL;
				$torrent = Bencode::decode((string)$response->getBody());
				$deferred->resolve($torrent);
			},
			function (Exception $error) use ($deferred)
			{
				$deferred->reject($error);
			}
		);
		return $deferred->promise();
	}

	/**
	 * Making the request to Tracker Server
	 * TODO: not finished
	 */
	private function	interogateUdp(array $metainfo): PromiseInterface
	{
		$deferred = new Deferred();
		$deferred->reject("udp not working.");

		$connector = new UdpFactory(Loop::get(), null, array(
			'bindto' => '0:' . $this->port
		));
		$connector->createClient($this->uri)->then(

			function (UdpSocket $tracker) use ($client)
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
				$timer = Loop::addPeriodicTimer(15.0, function ($timer)
					use (&$tracker, $connectPacket, &$retries)
				{
					if ($retries-- > 0)
					{
						echo "Retrying to connect to tracker server...", PHP_EOL;
						$tracker->send($connectPacket);
					} else {
						echo "tracker server is not responding.", PHP_EOL;
						Loop::cancelTimer($timer);
					}
				});

				$client->send("Connected to tracker!");
				echo "Connected to tracker!", PHP_EOL;
				// Get the data.
				$tracker->on('message', function ($data, $serverAddr, $tracker)
					use ($timer, $client)
				{
					Loop::cancelTimer($timer);
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
