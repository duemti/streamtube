<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Loop;
use App\Logic\StreamingServer\BittorrentClient;
use App\Logic\StreamingServer\Download;


class VideoStreamLogic implements MessageComponentInterface
{
	// Keep track of connected clients.
	private $clients;

	// Bittorrent client.
	private $btclient;

	// Instantiating the clients property.
	public function	__construct()
	{
		$this->clients = new \SplObjectStorage;
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
		//$btclient = new BittorrentClient($connection);
		//$btclient->start();
		echo "----------------------\n";

		$torrent = unserialize(file_get_contents(__DIR__.'/../../../storage/app/test2.trnt'));
		$down = new Download($torrent);
		echo "async...\n";
	}
}
