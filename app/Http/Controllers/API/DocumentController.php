<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Support\DocumentUploadHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentUploadHandler $upload) {}

    public function store(Request $request)
    {
        return $this->upload->store($request);
    }
}
