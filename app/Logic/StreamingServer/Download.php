<?PHP

namespace App\Logic\StreamingServer;

use React\EventLoop\LoopInterface;
use App\Logic\StreamingServer\Peer;


class Download
{
	// Bound port number.
	private $boundPort = 6002;

	// LoopInterface
	private $loop;

	// Torrent info.
	private $torrent;


	public function	__construct(LoopInterface $loop, array $torrent)
	{
		$this->loop = $loop;
		$this->torrent = $torrent;

		$this->start();
	}

	/**
	 * Download the torrent Async.
	 */
	private function	start()
	{
		$peer = new Peer(
			$this->loop,
			$this->torrent['info_hash'],
			$this->torrent['peer_id'],
			$this->boundPort
		);

		$peer->connect("5.39.95.125:52630");
	}
}
