<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Rhilip\Bencode\Bencode;

class VideoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
		//$torrent = file_get_contents('https://archive.org/download/ThePinkPanther-cartoons/ThePinkPanther-cartoons_archive.torrent');

		//Storage::disk('local')->put('metafiles/1.torrent', $torrent);

		$tfile = Storage::disk('local')->get('metafiles/1.torrent');

		$metainfo = Bencode::decode($tfile);
		echo "Title => ", $metainfo['title'], "</br>";
		echo "Announce => ", $metainfo['announce'], "</br>";
		//print_R($metainfo['info']);

		// Parsing Info Dictionary
		$info = $metainfo['info'];
		echo count($info);
		var_dump(array_keys($info));
		// Detect if torrent is single file or multiplefile
		if (isset($info['files']))
		{
			echo "</br>";
			echo $info['name'];
			echo "</br>";
			var_dump($info['collections']);
			foreach ($info['files'] as $files)
			{
				$path = implode('/', $files['path']);
				// Ignore hidden/padding files
				if (0 === strncmp('.', $path, 1))
					continue;
				echo $path, " - ", $files['length'], "bytes</br>";
			}
		}

		// Making the request to Tracker Server
		$info_hash = sha1(Bencode::encode($metainfo['info']), true);
		$peer_id = "0ST01nwlocvtoivh3e1b";
		$request = $metainfo['announce'] .
			"?info_hash=" . urlencode($info_hash) .
			"&peer_id=" . $peer_id;
		//$response = Bencode::decode(file_get_contents($request));
		//Storage::disk('local')->put('1.txt', serialize($response));
		$response = unserialize(Storage::disk('local')->get('1.txt'));

		echo "</br></br>";
		print_R($response);
		echo "</br></br>";

		// Get the peers (binary)
		if (!isset($response['peers']))
			die('error: response from tracker is inalid');
		$peers_data = array_map('ord', str_split($response['peers']));
		foreach (array_chunk($peers_data, 6) as $pd)
		{
			$peers[] = [
				'ip' => implode(':', array_slice($pd, 0, 4)),
				'port' => $pd[4] + $pd[5]
			];
		}
		print_R($peers);


		// Connect to peers

    }
}
