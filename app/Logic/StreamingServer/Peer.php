<?PHP

namespace App\Logic\StreamingServer;

use React\Stream\DuplexStreamInterface;
use React\Socket\ConnectionInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;


class Peer
{
	// Peer id
	private $id;

	// End peer connection
	private $conn;

	// Notifier - send notifications to Downloder.
	// communicate async with downloader.
	private $notifier;

	// New notifier, through promises.
	private $promises = array();

	// Interpreted by bits that represent pieces as in torrent file.
	private $remotePieces = "";

	// Buffering the messages.
	private $buffer = "";

	// Client connections start out as "choked" and "not interested".
	private $state = array(
		'handshake'			=> 'ko',
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

	public function	send(string $type, ...$fields)
	{
		switch ($type)
		{
			case 'handshake': $this->sendHandshake($fields[0]); break;
			case 'choke': $this->sendChoke(); break;
			case 'unchoke': $this->sendUnchoke(); break;
			case 'interested': $this->sendInterested(); break;
			case 'notinterested': $this->sendNotInterested(); break;
			case 'request': return $this->sendRequest($fields[0], $fields[1], $fields[2]); break;
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
		$this->sendPacket(chr(2));
	}

	/**
	 * Send interested packet to end peer.
	 */
	private function	sendNotInterested()
	{
		$this->state['am_interested'] = 0;
		$this->sendPacket(chr(3));
	}

	private function	sendPacket(string $payload)
	{
		$this->conn->write(pack('N', strlen($payload)) . $payload);
	}

	/**
	 * Request a Piece chunk from remote peer.
	 */
	private function	sendRequest(int $piece, int $block, int $length): PromiseInterface
	{
		$promise = new Deferred();

		$this->promises["$piece|$block"] = $promise;
		$this->sendPacket(pack('CNNN', 6, $piece, $block, $length));
		return $promise->promise();
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
	 * Ensures consistency with packets that are received.
	 */
	private function	parsePacket(string $data)
	{
		$this->buffer .= $data;

		if ($this->state['handshake'] !== 'ok')
		{
			if (68 <= strlen($this->buffer))
			{
				$payload = substr($this->buffer, 0, 68);
				$this->buffer = substr($this->buffer, 68);
				$this->checkHandshake($payload);
			}
			else
				return ;
			// else not full packet
		}

		// process the buffer untill there are no more full packets there
		while (4 <= strlen($this->buffer))
		{
			$len = unpack("N1", $this->buffer)[1];
			if ($len + 4 > strlen($this->buffer))
				break;

echo "PASS: $len < ", strlen($this->buffer), PHP_EOL;

			$id = ($len) ? ord($this->buffer[4]) : -1;
			$payload = ($len) ? substr($this->buffer, 5, $len - 1) : "";

			$this->buffer = substr($this->buffer, $len + 4);
			$this->onMessage($id, $payload);
		}
	}

	/**
	 */
	private function	incomingMessagesListener()
	{
		$peer = $this;

		$this->conn->on('data', function ($data) use ($peer) {
			$peer->parsePacket($data);
		});
	}

	/****************************************************************************
	 * Handles incoming Messages.
	 * **************************************************************************
	 * a kind of switcher.
	 */
	private function	onMessage(int $mid, string $payload)
	{
		$msgs = [
			-1 =>			'keepAlive',
			'choke',		'unchoke',
			'interested',	'notInterested',
			'have',			'bitfield',
			'request',		'piece',
			'cancel',		'port'
		];

		if (!isset($msgs[ $mid ]))
			echo "Warning: unknown message type '{$mid}' from peer.\n";
		else
		{
			if (!empty($payload))
				$this->{$msgs[ $mid ]}($payload);
			else
				$this->{$msgs[ $mid ]}();
		}
	}

	/**
	 * Keep-alive
	 */
	private function	keepAlive()
	{
		echo "Keep-alive Message.\n";
	}

	/**
	 * End Peer has CHOKED me.
	 */
	private function	choke()
	{
		echo "End peer has choked me.\n";
		$this->state['peer_choked'] = 1;
		$this->notify('choked');
	}

	/**
	 * End Peer has UNCHOKED me.
	 */
	private function	unchoke()
	{
		echo "End peer has un-choked me.\n";
		$this->state['peer_choked'] = 0;
		$this->notify('ready');
	}

	/**
	 * Whether or not the remote peer is interested in something this client has to offer.
	 */
	private function	interested()
	{
		echo "End peer interested in me.\n";
		$this->state['peer_interested'] = 1;
	}

	private function	notInterested()
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
	private function	bitfield(string $payload)
	{
		echo "Received bitfield\n";
		// too late to receive this message.
		if (!empty($this->remotePieces))
			return;

		$payload = unpack('a*', $payload)[1];
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
	private function	piece(string $payload)
	{
		var_dump(unpack("N2", substr($payload, 0, 8)));
		list(, $index, $begin) = unpack("N2", substr($payload, 0, 8));
		// The promise identificator.
		$pid = "$index|$begin";

		if (isset($this->promises[$pid]))
			$this->promises[$pid]->resolve([
				$index,
				$begin,
				substr($payload, 8)
			]);
		else
			echo "Warning: Unknown block received '$pid' ignoring...\n";
		$this->notify('ready');
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
