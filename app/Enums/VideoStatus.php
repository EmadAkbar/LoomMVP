<?php

namespace App\Enums;

enum VideoStatus: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
