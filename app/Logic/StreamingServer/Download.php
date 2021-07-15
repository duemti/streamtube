<?PHP

namespace App\Logic\StreamingServer;

use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use App\Logic\StreamingServer\Peer;


class Download
{
	// Bound port number.
	private $bindPort = 6002;

	// LoopInterface
	private $loop;

	// Torrent info.
	private $torrent;


	public function	__construct(LoopInterface $loop, array $torrent)
	{
		$this->loop = $loop;
		$this->torrent = $torrent;

		$this->start();
	}

	/**
	 * Download the torrent Async.
	 * TODO: support for multi-peer.
	 */
	private function	start()
	{
		$peers = new \SplObjectStorage();
		$uri = "5.39.95.125:52630";

		$this->connectToPeer($uri)->then(
			function (ConnectionInterface $conn) use ($peers)
			{
				echo "Connected to peer!\n";
				$peer = new Peer($conn);
				$peers->attach($peer);


				// TODO: algorithm to download.
				echo "Sending handshake...\n";
				$peer->send('handshake', $info_hash . $peer_id);
				echo "Sending handshake...\n";
				$peer->send('interested');
				echo "Piece length: ", $peer->torrent['piece_length'], PHP_EOL;
				$piece = 0;
				$block = 0;
				echo "Send request for piece:\t$piece,\t$block...\n";
				$peer->send('request', $payload);
			},
			function (Exception $error)
			{
				echo "Failed to connect to peer: ", $error->getMessage(), PHP_EOL;
			}
		);
	}

	private function	connectToPeer(string $uri)
	{
		$socket = new TcpConnector($this->loop, array(
			'timeout' => 10.0,
			'bindto' => "0.0.0.0:" . $this->bindPort
		));

		echo "Connecting to peer: $uri\n";
		return $socket->connect($uri);
	}
}
