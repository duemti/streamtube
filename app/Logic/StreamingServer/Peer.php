<?PHP

namespace App\Logic\StreamingServer;

use React\Stream\DuplexStreamInterface;
use React\Socket\ConnectionInterface;


class Peer
{
	// Peer id
	private $id;

	// End peer connection
	private $conn;

	// Notifier - send notifications to Downloder.
	// communicate async with downloader.
	private $notifier;

	// Interpreted by bits that represent pieces as in torrent file.
	private $remotePieces = "";

	// Buffering the messages.
	private $packet = "";

	// Client connections start out as "choked" and "not interested".
	private $state = array(
		'handshake'			=> '',
		'am_choking'		=> 1,
		'am_interested'		=> 0,
		'peer_choking'		=> 1,
		'peer_interested'	=> 0
	);

	public function	__construct(
		ConnectionInterface $conn,
		DuplexStreamInterface $notifier,
		string $id
	) {
		$this->conn = $conn;
		$this->notifier = $notifier;
		$this->id = $id;
		$this->incomingMessagesListener();
	}

	public function	send(string $type, string $payload = "")
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
	 * Notify the listener at the other end (i.e. downloader).
	 */
	private function	notify(string $msg)
	{
		$this->notifier->write($this->id . "|" . $msg);
	}

	/**
	 * Send a Bittorrent Protocol Handshake.
	 */
	private function	sendHandshake(string $payload)
	{
		$hs = chr(19) . "BitTorrent protocol" . pack("NN", 0, 0) . $payload;

		// save message for comparing against what peer sends.
		$this->state['handshake'] = $hs;
		$this->conn->write($hs);
	}

	/**
	 * Send interested packet to end peer.
	 */
	private function	sendInterested()
	{
		$this->state['am_interested'] = 1;
		$this->talk(2);
	}

	/**
	 * Send interested packet to end peer.
	 */
	private function	sendNotInterested()
	{
		$this->state['am_interested'] = 0;
		$this->talk(3);
	}

	private function	talk(int $mid, string $payload = "")
	{
		$this->conn->write(pack('N', strlen($payload) + 1) . $mid . $payload);
	}

	/**
	 * Request a Piece chunk from remote peer.
	 */
	private function	sendRequest(string $payload)
	{
		$this->talk(6, pack('NNN', $payload));
	}

	/**
	 * Check the received Handshake.
	 * Drop connection if it does not checks.
	 */
	private function	checkHandshake(string $data)
	{
		// Verify info_hash.
		if (substr($data, 28, 20) === substr($this->state['handshake'], 28, 20))
		{
			$this->state['handshake'] = 'ok';
			$this->notify('handshake-ok');
		}
		else
		{
			$this->conn->close();
			$this->notify('handshake-ko');
		}
	}

	/**
	 * Extract message from buffered container.
	 */
	private function	getPacket(int $len): string
	{
		$msg = substr($this->packet, 0, $len);
		$this->packet = substr($this->packet, $len);
		return $msg;
	}

	/**
	 * Ensures consistency with packets that are received.
	 */
	private function	incomingMessagesListener()
	{
		$peer = $this;

		$this->conn->on('data', function ($data) use ($peer)
		{
			$peer->packet .= $data;

			// make sure the end peer handshaked.
			if ('ok' !== $peer->state['handshake'])
				return $peer->checkHandshake($peer->getPacket(68));

			$len = unpack('N', substr($peer->packet, 0, 4))[1];

			if ($len + 4 <= strlen($peer->packet))
			{
				$packet = $peer->getPacket($len + 4);
				if (0 === $len)
					echo "Keep-alive Message.\n";
				else
					$peer->onMessage(substr($packet, 5, $len - 1));
			}
		});
	}

	/****************************************************************************
	 * Handles incoming Messages.
	 * **************************************************************************
	 * a kind of switcher.
	 */
	private function	onMessage(string $packet)
	{
		$mid = $packet[4];
		$data = substr($packet, 5);

		switch ($mid)
		{
			case 0: $this->choke($data); break;
			case 1: $this->unchoke($payload); break;
			case 2: $this->interested($data); break;
			case 3: $this->uninterested($data); break;
			case 4: $this->have($data, $mlen); break;
			case 5: $this->bitfield($data, $mlen); break;
			case 6: $this->request($data); break;
			case 7: $this->piece($payload); break;
			case 8: $this->cancel($data); break;
			default:
				echo "Warning: unknown message type '{$mid}' from peer.\n";
		}
	}

	/**
	 * End Peer has CHOKED me.
	 */
	private function	choke(string $data)
	{
		echo "End peer has choked me.\n";
		$this->state['peer_choked'] = 2;
	}

	/**
	 * End Peer has UNCHOKED me.
	 */
	private function	unchoke(string $data)
	{
		echo "End peer has un-choked me.\n";
		$this->state['peer_choked'] = 0;
		$this->notify('unchoke');
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
		var_dump($data);
		$this->notify('piece');
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
