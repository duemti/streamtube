<?PHP

namespace App\Logic\StreamingServer;

use React\Socket\ConnectionInterface;


class Peer
{
	// End peer connection
	private $conn;

	// Interpreted by bits that represent pieces as in torrent file.
	private $remotePieces = "";

	// Buffering the messages.
	private $packet = "";

	// Client connections start out as "choked" and "not interested".
	private $state = array(
		'handshake'			=> 0,
		'am_choking'		=> 1,
		'am_interested'		=> 0,
		'peer_choking'		=> 1,
		'peer_interested'	=> 0
	);

	public function	__construct(ConnectionInterface $conn)
	{
		$this->conn = $conn;
		$this->registerRouter();

	}

	public function	send(string $type, ?string $payload)
	{
		switch ($type)
		{
			case 'handshake': $this->sendHandshake($payload); break;
			case 'choke': $this->sendChoke(); break;
			case 'unchoke': $this->sendUnchoke(); break;
			case 'interested': $this->sendInterested(); break;
			case 'notinterested': $this->sendNotInterested(); break;
			case 'request': $this->sendRequest($payload); break;
			default: echo "Unknown message type: $type\n";
		}
	}


	/**
	 * Send a Bittorrent Protocol Handshake.
	 */
	private function	sendHandshake(string $payload)
	{
		$this->conn->write(chr(19)
			. "BitTorrent protocol"
			. pack("NN", 0, 0)
			. $payload
		);
	}

	/**
	 * Check the received Handshake.
	 */
	private function	checkHandshake(string $data)
	{
		if (('BitTorrent protocol' === unpack('a*', mb_strcut($data, 1, 19))[1])
			&& ($info_hash === mb_strcut($data, 28, 20)))
			echo "Successfull handshake!\n";
		else {
			// Dropping the connection.
			echo "Unsuccessfull handshake!\n";
			$connection->close();
			$deferred->reject("Unsuccessfull Handshake.");
		}
	}

	/**
	 * Ensures consistency with packets that are received.
	 */
	private function	registerRouter()
	{
		$peer = $this;

		$this->conn->on('data', function ($data) use ($peer)
		{
			if (0 === $peer->state['handshake'])
				return $peer->checkHandshake($data);

			$peer->packet .= $data;
			$len = unpack('N', substr($peer->packet, 0, 4))[1];

			if ($len + 4 <= strlen($peer->packet))
			{
				$peer->packet = substr($peer->packet, $len + 4);
				if (0 === $len)
					echo "Keep-alive Message.\n";
				else
					$peer->onMessage($peer->packet[4], substr($peer->packet, 5, $len - 1);
			}
		}
	}

	/**
	 * Handles incoming Messages.
	 * a kind of switcher.
	 */
	private function	onMessage(int $mid, string $payload)
	{
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
		}
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

	private function	sendInterested()
	{
		$this->state['am_interested'] = 1;
		$this->conn->write(pack('N', 1) . '2');
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
	 * Request a Piece chunk from remote peer.
	 */
	private function	sendRequest(string $payload)
	{
		$this->conn->write(
			pack('N', 13),
			6,
			pack('NNN', $payload);
		);
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
