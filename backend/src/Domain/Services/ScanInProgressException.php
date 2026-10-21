<?php
declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Thrown by LibraryScanner::scan() when another scan holds the scan lock (see
 * ScanRepositoryInterface::acquireLock). Nothing has been written when it is thrown.
 */
class ScanInProgressException extends \RuntimeException
{
}
