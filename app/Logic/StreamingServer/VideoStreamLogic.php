<?PHP

namespace App\Logic\StreamingServer;

use \Exception;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use App\Logic\StreamingServer\BittorrentClient;


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
		$btclient = new BittorrentClient();

		$connection->send('fetch torrent file..' . PHP_EOL);
		$btclient->fetchFile();
		foreach ($btclient->getMetainfo() as $v)
			$connection->send($v . PHP_EOL);

		$btclient->interogateTracker();
		
		try {
			foreach($btclient->getPeers() as $peer)
				$connection->send($peer['ip'] . ' :' . $peer['port'] . PHP_EOL);
		} catch (Exception $e) {
			echo $e->getMessage() . PHP_EOL;
		}

		$connection->send('connecting to peers..' . PHP_EOL);
		$btclient->start($this->loop, $connection);
		$connection->send('end.' . PHP_EOL);
	}
}
