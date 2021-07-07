<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Illuminate\Support\Facades\Storage;
use Rhilip\Bencode\Bencode;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use Ratchet\ConnectionInterface as RConnectionInterface;


class BittorrentClient
{
	private $torrentLink;
	private $torrent;
	private $metainfo;
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
		$this->metainfo = $metainfo;
		return [
			"Title => " . $metainfo['title'],
			"Announce => " . $metainfo['announce']
		];
	}

	function	interogateTracker()
	{
		// Making the request to Tracker Server
		$info_hash = sha1(Bencode::encode($this->metainfo['info']), true);
		$peer_id = "0ST01nwlocvtoivh3e1b";
		
		// The request URL
		$requestUrl = $this->metainfo['announce'] .
			"?info_hash=" . urlencode($info_hash) .
			"&peer_id=" . $peer_id;

		//$response = Bencode::decode(file_get_contents($requestUrl));
		//file_put_contents($this->filePath . '.meta', serialize($response));
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
		$peers_data = array_map('ord', str_split($this->trackerResponse['peers']));
		foreach (array_chunk($peers_data, 6) as $pd)
		{
			$peers[] = [
				'ip' => implode('.', array_slice($pd, 0, 4)),
				'port' => $pd[4] + $pd[5]
			];
		}
		$this->peers = $peers;
		return $peers;
	}

	public function	start(LoopInterface $loop, RConnectionInterface $client)
	{
		// Connect to peers
		$hostname = "tcp://" . $this->peers[2]['ip'];
		$port = $this->peers[2]['port'];

		$socket = new Connector($loop);
		
		$socket->connect($hostname . ":" . $port)->then(
			function (ConnectionInterface $connection) {
				echo "Connected!\n";
				$client->send("Connected" . PHP_EOL);
				// connected successfuly
				$connection->end();
				$connection->close();
			},
			function (Exception $error) {
				echo "Failed!\n";
				$client->send("Failed" . PHP_EOL);
				// failed to connect
			}
		);
	}
}
