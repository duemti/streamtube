<?PHP

namespace App\Logic\StreamingServer;

use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;
use App\Logic\StreamingServer\Peer;


class Download
{
	// Bound port number.
	private $bindPort = 6002;

	// LoopInterface
	private $loop;

	// Peers connections.
	private $peers;

	// Communicte async with peer objects.
	private $notifications;

	// Torrent info.
	private $torrent;


	public function	__construct(LoopInterface $loop, array $torrent)
	{
		$this->loop = $loop;
		$this->torrent = $torrent;
		$this->peers = new \Ds\Map();
		$this->notifications = new ThroughStream();

		$this->prepare();
		$this->start();
	}

	/**
	 * Prepares the communication with peers objects.
	 */
	private function	prepare()
	{
		$downloader = $this;

		// Data event.
		$this->notifications->on('data', function($data) use ($downloader) {
			echo "DATA Notifications.\n";
			list($id, $msg) = explode('|', $data);

			switch ($msg)
			{
				case 'handshake-ok':
					echo "Successfull handshake: $id\n";
					$downloader->peers->get($id)->send('interested');
					break;
				case 'handshake-ko':
					echo "Handshake failed with: $id\n";
					$downloader->peers->remove($id);
					break;
				case 'unchoke':
					echo "Total pieces: ", $downloader->torrent['pieces_count'], PHP_EOL;
					echo "Piece length: ", $downloader->torrent['info']['piece_length'], PHP_EOL;
					$piece = 0;
					$block = 0;
					$length = 2 ** 14;
					echo "Send request for piece:\t$piece,\t$block...\n";
					$downloader->peers->get($id)->send('request', $piece, $block, $length);
					break;
				case 'piece':
					echo "Received a Piece of piece!!!!\n";
					break;
				default: echo "Warning: Unknown notification '{$msg}'\n";
			}
		});

		// End event.
		$this->notifications->on('end', function() {
			echo "END Notifications.\n";
		});

		// Error event.
		$this->notifications->on('error', function(Exception $e) {
			echo "ERROR Notifications.\n";
		});

		// Close event.
		$this->notifications->on('close', function() {
			echo "CLOSE Notifications.\n";
		});
	}

	/**
	 * Download the torrent Async.
	 * TODO: support for multi-peer.
	 */
	private function	start()
	{
		$uri = "5.39.95.125:52630";

		$this->connectPeer($uri);
	}

	private function	connectPeer(string $uri)
	{
		$that = $this;
		$socket = new TcpConnector($this->loop, array(
			'timeout' => 10.0,
			'bindto' => "0.0.0.0:" . $this->bindPort
		));

		echo "Connecting to peer: $uri\n";
		return $socket->connect($uri)->then(
			function (ConnectionInterface $conn) use ($that, $uri)
			{
				echo "Connected to peer!\n";
				$peer = new Peer($conn, $that->notifications, $uri);

				echo "Sending handshake...\n";
				$peer->send('handshake', $that->torrent['info_hash'] . $that->torrent['peer_id']);
				$that->peers->put($uri, $peer);
			},
			function (Exception $error)
			{
				echo "Failed to connect to peer: ", $error->getMessage(), PHP_EOL;
			}
		);
	}
}
