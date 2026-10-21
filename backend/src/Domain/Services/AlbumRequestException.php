<?php

declare(strict_types=1);

namespace App\Domain\Services;

/** A request the service refuses; the code is the HTTP status the controller answers with. */
class AlbumRequestException extends \RuntimeException {}
