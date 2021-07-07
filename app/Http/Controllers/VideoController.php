<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Request;


class VideoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
		return view('video-stream', [
			'socketUrl' => 'ws://' . Request::getHost() . ':6001'
		]);
    }
}
