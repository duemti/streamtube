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

	function	fetchFile()
	{
		$torrent = file_get_contents($this->torrentLink);

		file_put_contents($this->filePath, $torrent);
		$this->torrent = $torrent;
	}

	function	getMetainfo()
	{
		$torrentFile = file_get_contents($this->filePath);

		$metainfo = Bencode::decode($torrentFile);

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
		$this->info_hash = sha1(Bencode::encode($metainfo['info']), true);
		$this->metainfo = $metainfo;
		return [
			"Title => " . $metainfo['title'],
			"Announce => " . $metainfo['announce']
		];
	}

	public function	interogateTracker($client)
	{
		$tracker = new Tracker(
			$this->loop,
			$client,
			$this->metainfo['announce'],
			$this->info_hash,
			$this->peer_id
		);

		// Execute in Sequential order.
		$tracker->interogate()
			->then(array($this, 'extractPeers'))
			->then(array($this, 'connectToPeer'));
	}

	// Get the peers (binary)
	function	extractPeers(array $trackerResponse): PromiseInterface
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
	public function	connectToPeer(string $uri)
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
