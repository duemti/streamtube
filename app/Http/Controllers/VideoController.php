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
		$torrent = file_get_contents('https://ia800304.us.archive.org/24/items/GoldriggersOf49/GoldriggersOf49_archive.torrent');

		Storage::disk('local')->put('metafiles/1.torrent', $torrent);

		$tfile = Storage::disk('local')->get('metafiles/1.torrent');

		$metainfo = Bencode::decode($tfile);
		echo $metainfo['title'];
    }
}
