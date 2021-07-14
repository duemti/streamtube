<?PHP

namespace App\Logic\StreamingServer;

use App\Logic\StreamingServer\Tracker;
use \Exception;
use Illuminate\Support\Facades\Storage;
use Rhilip\Bencode\Bencode;
use Ratchet\ConnectionInterface as WebSocketConnection;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;


class BittorrentClient
{
	// React loop interface
	private $loop;
	// Client from websocket end.
	private $webClient;

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
		// Execute in Sequential order:
		// 1. Fetch the torrent file from remote origin.
		// 2. Parse the torrent file.
		// 3. Interogate the Tracker Server.
		// 4. Parse the Tracker Server's response.
		// 5. Connect to a Peer.
		$this->fetchTorrent($this->torrentLink)
			->then(
				array($this, 'parseTorrent'),
				array($this, 'error'))
			->then(
				array(new Tracker($this->loop, $this->webClient), 'interogate'))
			->then(
				array($this, 'extractPeers'));
			/*->then(
				array($this, 'connectToPeer'),
				array($this, 'error'));
			 */
	}

	/**
	 * Echo error to stdout.
	 */
	public function	error(Exception $error)
	{
		echo "There was an error: ", $error->getMessage(), PHP_EOL;
		//throw new Exception($error);
	}

	/**
	 * Asynchroniously Download torrent file.
	 * return a promise.
	 */
	public function	fetchTorrent(string $uri): PromiseInterface
	{
		$deferred = new Deferred();
		$httpClient = new Browser($this->loop);

		echo "downloading the torrent file...\n";
		$httpClient->get($uri)->then(
			function (ResponseInterface $response) use ($deferred)
			{
				echo "downloaded.\n";
				$deferred->resolve((string)$response->getBody());
			},
			function (Exception $error) use ($deferred)
			{
				echo "failed.\n";
				$deferred->reject($error);
			}
		);
		return $deferred->promise();
	}

	/**
	 * TODO: not finished
	 */
	public function	parseTorrent(string $torrent): array
	{
		$metainfo = Bencode::decode($torrent);

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
				$tfile = $path. " - ". $files['length']. "bytes</br>";
				$this->webClient->send($tfile);
			}
		} else {
			// Single file torrent
		}
		$metainfo['info_hash'] = sha1(Bencode::encode($metainfo['info']), true);
		$metainfo['peer_id'] = $this->peer_id;
		return $metainfo;
	}


	// Get the peers (binary)
	public function	extractPeers(array $trackerResponse): PromiseInterface
	{
		$deferred = new Deferred();
		if (!isset($trackerResponse['peers']))
			$deferred->reject('Response from tracker is inalid.');

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
}
