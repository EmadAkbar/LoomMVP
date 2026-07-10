<?php

namespace App\Enums;

enum VideoPrivacy: string
{
    case Private = 'private';
    case Unlisted = 'unlisted';
    case Password = 'password';
    case Disabled = 'disabled';
}
