<?PHP

namespace App\Logic\StreamingServer;

use App\Logic\StreamingServer\Tracker;
use \Exception;
use Illuminate\Support\Facades\Storage;
use Rhilip\Bencode\Bencode;
use Ratchet\ConnectionInterface as WebSocketConnection;
use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;


class BittorrentClient
{
	// Bittorrent client port.
	private $port = 6002; 
	// React loop interface
	private $loop;

	private $torrentLink;
	private $torrent;
	private $metainfo;
	private $info_hash;
	private $peer_id = "0ST01nwlocvtoivh3e1b";
	private $filePath = __DIR__ . '/../../../storage/app/test1.torrent';
	private $trackerResponse;
	private $peers;

	public function	__construct(LoopInterface $loop, WebSocketConnection $webClient, string $torrentLink = 'https://archive.org/download/sintel-torrent/sintel-torrent_archive.torrent'/*'https://webtorrent.io/torrents/sintel.torrent'*/)
	{
		$this->loop = $loop;
		$this->webClient = $webClient;
		$this->torrentLink = $torrentLink;
	}

	public function	start()
	{
		);

		// Execute in Sequential order:
		// 1. Fetch the torrent file from remote origin.
		// 2. Parse the torrent file.
		// 3. Interogate the Tracker Server.
		// 4. Parse the Tracker Server's response.
		// 5. Connect to a Peer.
		$this->fetchTorrent()
			->then(array($this, 'parseTorrent'))
			->then(array(new Tracker(
					$this->loop,
					$this->client,
					$this->metainfo,
				), 'interogate'))
			->then(array($this, 'extractPeers'))
			->then(array($this, 'connectToPeer'));
	}

	private function	fetchTorrent()
	{
		// todo: make async.
		$torrent = file_get_contents($this->torrentLink);

		file_put_contents($this->filePath, $torrent);
		$this->torrent = $torrent;
	}

	private function	parseTorrent(string $torrent)
	{
		$deferred = new Deferred();
		$metainfo = Bencode::decode($torrent);

/*
		// Parsing Info Dictionary
		$info = $metainfo['info'];
		// Multiple torrent file
		if (isset($info['files']))
		{
			echo $info['name'];
			foreach ($info['files'] as $files)
			{
				$path = implode('/', $files['path']);
				// Ignore hidden/padding files
				if (0 === strncmp('.', $path, 1))
					continue;
				//echo $path, " - ", $files['length'], "bytes</br>";
			}
		} else {
			// Single file torrent
		}
*/
		$metainfo[] = array(
			'info_hash' => sha1(Bencode::encode($metainfo['info']), true)
		);
		$deferred->resolve($metainfo);
		return $deferred->promise();
	}


	// Get the peers (binary)
	private function	extractPeers(array $trackerResponse): PromiseInterface
	{
		if (!isset($trackerResponse['peers']))
			throw new Exception('Response from tracker is inalid.');

		$deferred = new Deferred();
		$peers = array();
		$peers_data = str_split($trackerResponse['peers']);
		foreach (array_chunk($peers_data, 6) as $pd)
		{
			$peers[] = [
				'ip' => implode('.', array_map('ord', array_slice($pd, 0, 4))),
				'port' => unpack("n", $pd[4] . $pd[5])[1]
			];
		}
		$this->peers = $peers;
		var_dump($peers);
		$uri = $peers[0]['ip'] . ':' .  $peers[0]['port'];
		//$uri = '174.138.32.158:5000';
		$deferred->resolve($uri);
		return $deferred->promise();
	}

	/**
	 * Establish TCP connection to peer
	 */
	private public function	connectToPeer(string $uri)
	{
		$loop = $this->loop;
		$client = $this->webClient;
		$socket = new TcpConnector($loop, array(
			'bindto' => '0.0.0.0:' . $this->port
		));

		$client->send("connecting......" . PHP_EOL);
		$socket->connect($uri)->then(
			function (ConnectionInterface $connection) use ($client) {
				// connected successfuly
				echo "Connected!\n";
				// TODO: start requesting pieces.
				//$connection->end();
				$client->send("Succeded!!!" . PHP_EOL);
			},
			function (Exception $error) use ($client) {
				echo "Failed!\n";
				$client->send("Failed: " . $error->getMessage() . PHP_EOL);
				// failed to connect
			}
		);
	}
}
