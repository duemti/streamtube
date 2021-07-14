<?PHP

namespace App\Logic\StreamingServer;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;


/**
 * TODO: make this Factory pattern.
 */
class Peer
{
	// Interpreted by bits that represent pieces as in torrent file.
	private $remotePieces = "";
	private $loop;
	private $info_hash;
	private $boundPort;


	// Client connections start out as "choked" and "not interested".
	private $state = array(
		'am_choking'		=> 1,
		'am_interested'		=> 0,
		'peer_choking'		=> 1,
		'peer_interested'	=> 0
	);

	public function	__construct(LoopInterface $loop, string $info_hash, string $peer_id, string $boundPort)
	{
		$this->loop = $loop;
		$this->info_hash = $info_hash;
		$this->boundPort = $boundPort;
	}

	/**
	 * Establish TCP connection to peer.
	 * return a established connection to a peer OR rejected promise.
	 */
	public function	connect(string $uri)
	{
		$deferred = new Deferred();
		$socket = new TcpConnector($this->loop, array(
			'timeout' => 10.0,
			'bindto' => "0.0.0.0:" . $this->boundPort
		));
		$peer = $this;

		echo "Connecting to peer: $uri\n";
		$socket->connect($uri)->then(
			function (ConnectionInterface $connection)
				use ($deferred, $peer)
			{
				// connected successfuly
				echo "Connected!\n";

				echo "performing the handshake...\n";
				$peer->handshake($connection);

				$connection->on('data', function ($data) use ($peer) {
					// Check returned handshake.
					// Drop connection id info_hash doesn't match.
					if ('BitTorrent protocol' === unpack('a*', mb_strcut($data, 1, 19))[1])
					{
						if ($peer->info_hash === mb_strcut($data, 28, 20))
						{
							$deferred->reject('Peer info_hash doesn\'t match.');
							echo "Successfull handshake!\n";
						}
						else
							echo "Unsuccessfull handshake.\n";
						return;
					}

					// Message Length
					$mlen = unpack('N', mb_strcut($data, 0, 4))[1];

					// Ignore Keep-alive messages.
					if (0 === $mlen)
						return;

					// Message ID
					$mid = $data[4];
					
					switch ($mid)
					{
						case 0: $peer->choke($data); break;
						case 1: $peer->unchoke($data); break;
						case 2: $peer->interested($data); break;
						case 3: $peer->uninterested($data); break;
						case 4: $peer->have($data, $mlen); break;
						case 5: $peer->bitfield($data, $mlen); break;
						case 6: $peer->request($data); break;
						case 7: $peer->piece($data); break;
						case 8: $peer->cancel($data); break;
						default:
							echo "Warning: unknown message type '{$mid}' from peer.\n";
					}
				});

				$connection->on('close', function () use ($deferred) {
					echo "Peer dropped the connection.", PHP_EOL;
					$deferred->reject("Peer dropped connection");
				});

				// TODO: start requesting pieces.
			},
			function (Exception $error) use ($deferred)
			{
				echo $error->getMessage(), PHP_EOL;
				$deferred->reject($error);
			}
		);
		return $deferred->promise();
	}

	/**
	 * Send a Bittorrent Protocol Handshake.
	 */
	private function	handshake(ConnectionInterface $connection)
	{
		$handshake = chr(19)
			. "BitTorrent protocol"
			. pack("NN", 0, 0)
			. $this->info_hash
			. $this->peer_id;
		var_dump($this->info_hash);

		$connection->write($handshake);
	}

	/**
	 * End Peer has CHOKED me.
	 */
	private function	choke(string $data)
	{
		echo "End peer has choked me.\n";
		$this->state['peer_choked'] = 1;
	}

	/**
	 * End Peer has UNCHOKED me.
	 */
	private function	unchoke(string $data)
	{
		echo "End peer has un-choked me.\n";
		$this->state['peer_choked'] = 0;
	}

	/**
	 * Whether or not the remote peer is interested in something this client has to offer.
	 */
	private function	interested(string $data)
	{
		echo "End peer interested in me.\n";
		$this->state['peer_interested'] = 1;
	}

	private function	notInterested(string $data)
	{
		echo "End peer not-interested in me.\n";
		$this->state['peer_interested'] = 0;
	}

	/**
	 * One message of fixed-length per on piece.
	 */
	private function	have(string $data, int $mlen)
	{
		$piece = ord($data[5]);
		$this->remotePieces |= 0b1 << $piece;
		echo "Received HAVE for piece: $piece\n";
	}

	/**
	 * Received immediately after the handshake.
	 * Each bit in data represent that either the end-peer have, 1, or not have, 0, a piece.
	 */
	private function	bitfield(string $data, int $mlen)
	{
		echo "Received bitfield\n";
		// too late to receive this message.
		if (!empty($this->remotePieces))
			return;

		$payload = unpack('a*', mb_strcut($data, 5, $mlen - 1))[1];
		$this->remotePieces = $payload;
	}


	/**
	 * Request. IGNORE this message.
	 */
	private function	request(string $data)
	{
		echo "Received a REQUEST, ignoring...\n";
	}

	/**
	 * <len=0009+X><id=7><index><begin><block>
	 * index: integer specifying the zero-based piece index
	 * begin: integer specifying the zero-based byte offset within the piece
	 * block: block of data, which is a subset of the piece specified by index.
	 */
	private function	piece(string $data)
	{
	}

	/**
	 */
	private function	cancel(string $data)
	{
	}

	/**
	 */
	private function	port(string $data)
	{
	}
}
