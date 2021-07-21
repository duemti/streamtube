<?PHP

namespace App\Logic\StreamingServer;

use React\EventLoop\Loop;
use React\Socket\TcpConnector;
use React\Socket\TimeoutConnector;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;
use App\Logic\StreamingServer\Peer;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class Download
{
	// Bound port number.
	private $bindPort = 6002;

	// Block length
	private $blockLen = 16384;

	// Peers connections.
	private $peers;

	// Communicte async with peer objects.
	private $notifications;

	// Torrent info.
	private $torrent;

	// progress
	private $progress;

	// Requested pieces
	private $pieces;

	private $reqList;


	public function	__construct(array $torrent)
	{
		$this->torrent = $torrent;
		$this->info = $torrent['info'];
		$this->peers = new \Ds\Map();
		$this->notifications = new ThroughStream();
		$this->reqList = new \Ds\Set();
		$this->pieceLen = $torrent['info']['piece length'];
		
		$this->progress = 0;
		//$fb = $this->info['piece length'] / 16384;
		//$progress = array(
			//'pieces-count' => strlen($this->info['pieces']) / 20,
			//'full_blocks' => floor($fb),
			//'last_block' => ($this->info['piece length'] - ($fb * 16384))
		//];

		$this->prepare();
		$this->start();
	}

	private function	hashcheck(int $piece)
	{
		var_dump(count($this->pieces));
		echo "END.\nplength=", $this->pieceLen, ", progress=", $this->progress, PHP_EOL;
		$ph = substr($this->info['pieces'], $piece * 20, 20);
		$dh = sha1(implode($this->pieces[$piece]), true);
		if ($dh === $ph)
			echo "Hash for piece $piece matches.\n";
		else
			echo "Error: Hash doesn't matches!!!\n";
		echo "$ph\n$dh\n";
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
			list($id, $type) = explode('|', $data);

			switch ($type)
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
					echo "Piece length: ", $downloader->torrent['info']['piece length'], PHP_EOL;
					$downloader->download($id);
					break;
				case 'piece':
					echo "Received a Piece of piece!!!!\n";
					break;
				case 'ready':
					echo "$id is Ready.\n";
					$downloader->download($id);
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
		//$uri = "5.39.95.125:52630";
		// It works!
		$uri = "192.168.0.100:52200";

		$this->connectPeer($uri);
	}

	private function	connectPeer(string $uri)
	{
		$that = $this;
		$socket = new TcpConnector(null, array(
			'bindto' => "0.0.0.0:" . $this->bindPort
		));

		//$socket = new TimeoutConnector($socket, 15.0);

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

	/**
	 * TODO: prob this is not async, make it async with promises.
	 * currently requesting blocks with promises.
	 * now i need to request whole pieces with promises too then hash check them.
	 */
	private function	download(string $id)
	{
		$peer = $this->peers->get($id);
		$index = 0;


		$length = $this->blockLen;
		if ($this->progress === $this->pieceLen)
			return $this->hashcheck($index);
		elseif ($this->progress + $length > $this->pieceLen)
			$length = $this->pieceLen - $this->progress;
		$this->requestBlock($peer, $index, $this->progress, $length);
		$this->progress += $length;
	}

	/**
	 * Send ASYNC request for a block.
	 */
	private function	requestBlock(Peer $peer, int $index, int $begin, int $length)
	{
		$that = $this;
		echo "Send request for: p=$index, b=$begin\n";
		$this->reqList->add("$index|$begin");
		$peer->send('request', $index, $begin, $length)->then(
			function (array $payload) use ($that) {
				list($index, $begin, $block) = $payload;

				$that->pieces[$index][$begin] = $block;
				$that->reqList->remove("$index|$begin");
			},
			function ($e) {
				echo "Error: ", $e->getMessage(), PHP_EOL;
			}
		);
	}
}
