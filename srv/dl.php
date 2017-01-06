<?php

/**
 * Get the value of a header in the current request context
 *
 * @param string $name Name of the header
 * @return string|null Returns null when the header was not sent or cannot be retrieved
 */
function get_request_header($name)
{
    $name = strtoupper($name);

    // IIS/Some Apache versions and configurations
    if (isset($_SERVER['HTTP_' . $name])) {
        return trim($_SERVER['HTTP_' . $name]);
    }

    // Various other SAPIs
    foreach (apache_request_headers() as $name => $value) {
        if (strtoupper($name) === $name) {
            return trim($value);
        }
    }

    return null;
}

class NonExistentFileException extends \RuntimeException {}
class UnreadableFileException extends \RuntimeException {}
class UnsatisfiableRangeException extends \RuntimeException {}
class InvalidRangeHeaderException extends \RuntimeException {}

class RangeHeader
{
    /**
     * The first byte in the file to send (0-indexed), a null value indicates the last
     * $end bytes
     *
     * @var int|null
     */
    private $firstByte;

    /**
     * The last byte in the file to send (0-indexed), a null value indicates $start to
     * EOF
     *
     * @var int|null
     */
    private $lastByte;

    /**
     * Create a new instance from a Range header string
     *
     * @param string $header
     * @return RangeHeader
     */
    public static function createFromHeaderString($header)
    {
        if ($header === null) {
            return null;
        }
    
        if (!preg_match('/^(bytes)=(\d*)-(\d*)$/', $header, $info)) {
            throw new InvalidRangeHeaderException('Invalid header format');
        } else {
            if (strtolower($info[1]) !== 'bytes') {
                throw new InvalidRangeHeaderException('Unknown range unit: ' . $info[1]);
            }
        }

        return new self(
            $info[2] === '' ? null : $info[2],
            $info[3] === '' ? null : $info[3]
        );
    }

    /**
     * @param int|null $firstByte
     * @param int|null $lastByte
     * @throws InvalidRangeHeaderException
     */
    public function __construct($firstByte, $lastByte)
    {
        $this->firstByte = $firstByte === null ? $firstByte : (int)$firstByte;
        $this->lastByte = $lastByte === null ? $lastByte : (int)$lastByte;

        if ($this->firstByte === null && $this->lastByte === null) {
            throw new InvalidRangeHeaderException(
                'Both start and end position specifiers empty'
            );
        } else if ($this->firstByte < 0 || $this->lastByte < 0) {
            throw new InvalidRangeHeaderException(
                'Position specifiers cannot be negative'
            );
        } else if ($this->lastByte !== null && $this->lastByte < $this->firstByte) {
            throw new InvalidRangeHeaderException(
                'Last byte cannot be less than first byte'
            );
        }
    }

    /**
     * Get the start position when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int
     * @throws UnsatisfiableRangeException
     */
    public function getStartPosition($fileSize)
    {
        $size = (int)$fileSize;

        if ($this->firstByte === null) {
            return ($size - 1) - $this->lastByte;
        }

        if ($size <= $this->firstByte) {
            throw new UnsatisfiableRangeException(
                'Start position is after the end of the file'
            );
        }

        return $this->firstByte;
    }

    /**
     * Get the end position when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int
     * @throws UnsatisfiableRangeException
     */
    public function getEndPosition($fileSize)
    {
        $size = (int)$fileSize;

        if ($this->lastByte === null) {
            return $size - 1;
        }

        if ($size <= $this->lastByte) {
            throw new UnsatisfiableRangeException(
                'End position is after the end of the file'
            );
        }

        return $this->lastByte;
    }

    /**
     * Get the length when this range is applied to a file of the specified size
     *
     * @param int $fileSize
     * @return int
     * @throws UnsatisfiableRangeException
     */
    public function getLength($fileSize)
    {
        $size = (int)$fileSize;

        return $this->getEndPosition($size) - $this->getStartPosition($size) + 1;
    }

    /**
     * Get a Content-Range header corresponding to this Range and the specified file
     * size
     *
     * @param int $fileSize
     * @return string
     */
    public function getContentRangeHeader($fileSize)
    {
        return 'bytes ' . $this->getStartPosition($fileSize) . '-'
             . $this->getEndPosition($fileSize) . '/' . $fileSize;
    }
}

class PartialFileServlet
{
    /**
     * The range header on which the data transmission will be based
     *
     * @var RangeHeader|null
     */
    private $range;

    /**
     * @param RangeHeader $range Range header on which the transmission will be based
     */
    public function __construct(RangeHeader $range = null)
    {
        $this->range = $range;
    }

    /**
     * Send part of the data in a seekable stream resource to the output buffer
     *
     * @param resource $fp Stream resource to read data from
     * @param int $start Position in the stream to start reading
     * @param int $length Number of bytes to read
     * @param int $chunkSize Maximum bytes to read from the file in a single operation
     */
    private function sendDataRange($fp, $start, $length, $chunkSize = 8192)
    {
        if ($start > 0) {
            fseek($fp, $start, SEEK_SET);
        }

        while ($length) {
            $read = ($length > $chunkSize) ? $chunkSize : $length;
            $length -= $read;
            echo fread($fp, $read);
        }
    }

    /**
     * Send the headers that are included regardless of whether a range was requested
     *
     * @param string $fileName
     * @param int $contentLength
     * @param string $contentType
     */
    private function sendDownloadHeaders($fileName, $contentLength, $contentType)
    {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $contentLength);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Accept-Ranges: bytes');
    }

    /**
     * Send data from a file based on the current Range header
     *
     * @param string $path Local file system path to serve
     * @param string $contentType MIME type of the data stream
     */
    public function sendFile($path, $contentType = 'application/octet-stream')
    {
        // Make sure the file exists and is a file, otherwise we are wasting our time
        $localPath = realpath($path);
        if ($localPath === false || !is_file($localPath)) {
            throw new NonExistentFileException(
                $path . ' does not exist or is not a file'
            );
        }

        // Make sure we can open the file for reading
        if (!$fp = fopen($localPath, 'r')) {
            throw new UnreadableFileException(
                'Failed to open ' . $localPath . ' for reading'
            );
        }

        $fileSize = filesize($localPath);

        if ($this->range == null) {
            // No range requested, just send the whole file
            header('HTTP/1.1 200 OK');
            $this->sendDownloadHeaders(basename($localPath), $fileSize, $contentType);

            fpassthru($fp);
        } else {
            // Send the request range
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: ' . $this->range->getContentRangeHeader($fileSize));
            $this->sendDownloadHeaders(
                basename($localPath),
                $this->range->getLength($fileSize),
                $contentType
            );

            $this->sendDataRange(
                $fp,
                $this->range->getStartPosition($fileSize),
                $this->range->getLength($fileSize)
            );
        }

        fclose($fp);
    }
}

$path = 'file path that you want to download on local';
$contentType = 'application/octet-stream';

// Avoid sending unexpected errors to the client - we should be serving a file,
// we don't want to corrupt the data we send
// ini_set('display_errors', '0');

try {
    $rangeHeader = RangeHeader::createFromHeaderString(get_request_header('Range'));
    (new PartialFileServlet($rangeHeader))->sendFile($path, $contentType);
} catch (InvalidRangeHeaderException $e) {
    error_log($e->__toString());
    header("HTTP/1.1 400 Bad Request");
} catch (UnsatisfiableRangeException $e) {
    header("HTTP/1.1 416 Range Not Satisfiable");
} catch (NonExistentFileException $e) {
    header("HTTP/1.1 404 Not Found");
} catch (UnreadableFileException $e) {
    header("HTTP/1.1 500 Internal Server Error");
}

// It's usually a good idea to explicitly exit after sending a file to avoid sending any
// extra data on the end that might corrupt the file
exit;