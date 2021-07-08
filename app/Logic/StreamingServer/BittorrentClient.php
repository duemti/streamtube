<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Illuminate\Support\Facades\Storage;
use Rhilip\Bencode\Bencode;
use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use Ratchet\ConnectionInterface as RConnectionInterface;


class BittorrentClient
{
	private $port = 6881;

	private $torrentLink;
	private $torrent;
	private $metainfo;
	private $info_hash;
	private $peer_id = "0ST01nwlocvtoivh3e1b";
	private $filePath = __DIR__ . '/../../../storage/app/test1.torrent';
	private $trackerResponse;
	private $peers;

	public function	__construct(string $torrentLink = 'https://archive.org/download/ThePinkPanther-cartoons/ThePinkPanther-cartoons_archive.torrent')
	{
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

	function	interogateTracker()
	{
		// Making the request to Tracker Server
		
		// The request URL
		$requestUrl = $this->metainfo['announce'] .
			"?info_hash=" . urlencode($this->info_hash) .
			"&peer_id=" . urlencode($this->peer_id) .
			"&port=" . $this->port;

		$response = Bencode::decode(file_get_contents($requestUrl));
		file_put_contents($this->filePath . '.meta', serialize($response));
		$response = unserialize(file_get_contents($this->filePath . '.meta'));
		$this->trackerResponse = $response;
		return $trackerResponse;
	}

	// Get the peers (binary)
	function	getPeers()
	{
		if (!isset($this->trackerResponse['peers']))
			throw new Exception('Response from tracker is inalid.');

		$peers = array();
		$peers_data = str_split($this->trackerResponse['peers']);
		foreach (array_chunk($peers_data, 6) as $pd)
		{
			$peers[] = [
				'ip' => implode('.', array_map('ord', array_slice($pd, 0, 4))),
				'port' => unpack("n", $pd[4] . $pd[5])[1]
			];
		}
		$this->peers = $peers;
		return $peers;
	}

	public function	start(
		LoopInterface $loop,
		RConnectionInterface $client
	) {
		// Connect to peers
		$uri = $this->peers[2]['ip'] . ':' .  $this->peers[2]['port'];

		$socket = new TcpConnector($loop, array(
			'bindto' => '0.0.0.0:6881'
		));
		$client->send("connecting......" . PHP_EOL);
		$socket->connect($uri)->then(
			function (ConnectionInterface $connection) use ($client) {
				// connected successfuly
				echo "Connected!\n";
				$connection->end();
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
