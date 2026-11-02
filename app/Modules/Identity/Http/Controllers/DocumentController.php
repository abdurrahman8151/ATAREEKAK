<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Handlers\DocumentUploadHandler;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentUploadHandler $upload) {}

    public function store(Request $request)
    {
        return $this->upload->store($request);
    }
}
