<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UploadFileAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/uploads',
        controller: UploadFileAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_upload_file'
    ),
])]
final class UploadFile
{
}
