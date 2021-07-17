<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use App\Logic\StreamingServer\BittorrentClient;
use App\Logic\StreamingServer\Download;


class VideoStreamLogic implements MessageComponentInterface
{
	// Keep track of connected clients.
	private $clients;

	// Bittorrent client.
	private $btclient;

	// Server loop.
	private $loop;

	// Instantiating the clients property.
	public function	__construct(LoopInterface $loop)
	{
		$this->clients = new \SplObjectStorage;
		$this->loop = $loop;
	}

	public function	onOpen(ConnectionInterface $connection)
	{
		$this->clients->attach($connection);

		echo "New connection! {$connection->resourceId}\n";
		$this->initbtc($connection);
	}

	public function	onMessage(ConnectionInterface $connection, $message)
	{
		foreach ($this->clients as $client)
		{
			if ($client !== $connection)
				$client->send($message);
		}
	}

	public function	onClose(ConnectionInterface $connection)
	{
		$this->clients->detach($connection);
		echo "Clinet {$connection->resourceId} disconnected.\n";
	}

	public function	onError(ConnectionInterface $connection, \Exception $e)
	{
		echo "Error: {$e->getMessage}.\n";
		$connection->close;
	}


	// Bussiness Logic
	private function	initbtc(ConnectionInterface $connection)
	{
		// todo: provide torrent link
		$btclient = new BittorrentClient($this->loop, $connection);
		$btclient->start();
		echo "----------------------\n";

		$metainfo = [
			'info_hash' => "802eeb8958d6e8008d8a39ebd39f68ac9725307a",
			'peer_id' => "-ST2021-123456789123"
		];
		$torrent = unserialize(file_get_contents(__DIR__.'/../../../storage/app/test2.trnt'));
		$down = new Download($this->loop, $torrent);
		echo "async...\n";
	}
}
