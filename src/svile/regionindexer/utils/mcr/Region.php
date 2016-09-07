<?php

/*
 *                _   _
 *  ___  __   __ (_) | |   ___
 * / __| \ \ / / | | | |  / _ \
 * \__ \  \ / /  | | | | |  __/
 * |___/   \_/   |_| |_|  \___|
 *
 * @Author: svile
 * @Kik: _svile_
 * @Telegram_Group: https://telegram.me/svile
 * @E-mail: thesville@gmail.com
 * @Github: https://github.com/svilex
 *
 */

namespace svile\regionindexer\utils\mcr;


use svile\regionindexer\utils\binary\Binary;


final class Region
{
    const COMPRESSION_GZIP = 1;
    const COMPRESSION_ZLIB = 2;
    const MAX_SECTOR_LENGTH = 256 << 12; //256 sectors, (1 MiB)

    /** @var int */
    private $x = 0;
    /** @var int */
    private $z = 0;
    /** @var string */
    private $filePath = '';
    /** @var resource */
    private $filePointer;
    /** @var int */
    private $lastSector = 0;
    /** @var int[] */
    private $locationTable = [];


    public function __construct(int $x, int $z, string $path = '')
    {
        $this->x = $x;
        $this->z = $z;
        $this->filePath = $path;
        $this->filePointer = fopen($this->filePath, 'r+b');
        stream_set_read_buffer($this->filePointer, 1024 * 16);//16KB
        stream_set_write_buffer($this->filePointer, 1024 * 16);//16KB
        if (file_exists($this->filePath))
            $this->loadLocationTable();
    }


    public function __destruct()
    {
        $this->close();
    }


    private function isChunkGenerated($index)
    {
        return !($this->locationTable[$index][0] === 0 or $this->locationTable[$index][1] === 0);
    }


    /**
     * @param int $x
     * @param int $z
     * @param bool $data
     * @return null|string|Chunk
     */
    public function readChunk(int $x = 0, int $z = 0, $data = false)
    {
        $index = self::getChunkOffset($x, $z);
        if ($index < 0 or $index >= 4096)
            return null;

        if (!$this->isChunkGenerated($index))
            return null;

        fseek($this->filePointer, $this->locationTable[$index][0] << 12);
        $length = Binary::readInt(fread($this->filePointer, 4));
        $compression = ord(fgetc($this->filePointer));

        if ($length <= 0 or $length > self::MAX_SECTOR_LENGTH) {//Not yet generated / corrupted
            if ($length >= self::MAX_SECTOR_LENGTH) {
                $this->locationTable[$index][0] = ++$this->lastSector;
                $this->locationTable[$index][1] = 1;
                echo 'Corrupted chunk header detected' . PHP_EOL;
            }
            return null;
        }

        if ($length > ($this->locationTable[$index][1] << 12)) {//Invalid chunk, bigger than defined number of sectors
            echo 'Corrupted bigger chunk detected' . PHP_EOL;
            $this->locationTable[$index][1] = $length >> 12;
            $this->writeLocationIndex($index);
        } elseif ($compression !== self::COMPRESSION_ZLIB and $compression !== self::COMPRESSION_GZIP) {
            echo 'Invalid compression type' . PHP_EOL;
            return null;
        }

        if ($data)
            return fread($this->filePointer, $length - 1);
        else
            return Chunk::fromBinary(fread($this->filePointer, $length - 1));
    }


    private function saveChunk(int $x = 0, int $z = 0, string $chunkData = '')
    {
        $length = strlen($chunkData) + 1;
        if ($length + 4 > self::MAX_SECTOR_LENGTH)
            echo 'Chunk is too big! ' . PHP_EOL;
        $sectors = (int)ceil(($length + 4) / 4096);
        $index = self::getChunkOffset($x, $z);
        $indexChanged = false;
        if ($this->locationTable[$index][1] < $sectors) {
            $this->locationTable[$index][0] = $this->lastSector + 1;
            $this->lastSector += $sectors;//The GC will clean this shift "later"
            $indexChanged = true;
        } elseif ($this->locationTable[$index][1] != $sectors) {
            $indexChanged = true;
        }

        $this->locationTable[$index][1] = $sectors;
        $this->locationTable[$index][2] = time();

        fseek($this->filePointer, $this->locationTable[$index][0] << 12);
        fwrite($this->filePointer, str_pad(Binary::writeInt($length) . chr(self::COMPRESSION_ZLIB) . $chunkData, $sectors << 12, "\x00", STR_PAD_RIGHT));

        if ($indexChanged)
            $this->writeLocationIndex($index);
    }


    public function removeChunk(int $x = 0, int $z = 0)
    {
        $index = self::getChunkOffset($x, $z);
        $this->locationTable[$index][0] = 0;
        $this->locationTable[$index][1] = 0;
    }


    /**
     * @param $chunk
     * @param int $x
     * @param int $z
     *
     * X-Z must be relative to the region
     */
    public function writeChunk($chunk, int $x = 0, int $z = 0)
    {
        if ($chunk instanceof Chunk)
            $chunkData = $chunk->toBinary();
        else
            $chunkData = $chunk;
        if ($chunkData !== false)
            $this->saveChunk($x, $z, $chunkData);
    }


    /**
     * @param int $x
     * @param int $z
     * @return int
     */
    private static function getChunkOffset(int $x = 0, int $z = 0)
    {
        return $x + ($z << 5);
    }


    public function close()
    {
        if (is_resource($this->filePointer)) {
            $this->writeLocationTable();
            fclose($this->filePointer);
        }
    }


    /**
     * @return int
     */
    public function doSlowCleanUp()
    {
        for ($i = 0; $i < 1024; ++$i) {
            if ($this->locationTable[$i][0] === 0 or $this->locationTable[$i][1] === 0)
                continue;
            fseek($this->filePointer, $this->locationTable[$i][0] << 12);
            $chunk = fread($this->filePointer, $this->locationTable[$i][1] << 12);
            $length = Binary::readInt(substr($chunk, 0, 4));
            if ($length <= 1)
                $this->locationTable[$i] = [0, 0, 0];//Non-generated chunk, remove it from index

            try {
                $chunk = zlib_decode(substr($chunk, 5));
            } catch (\Throwable $e) {
                $this->locationTable[$i] = [0, 0, 0];//Corrupted chunk, remove it
                continue;
            }

            $chunk = chr(self::COMPRESSION_ZLIB) . zlib_encode($chunk, ZLIB_ENCODING_DEFLATE, 9);
            $chunk = Binary::writeInt(strlen($chunk)) . $chunk;
            $sectors = (int)ceil(strlen($chunk) / 4096);
            if ($sectors > $this->locationTable[$i][1]) {
                $this->locationTable[$i][0] = $this->lastSector + 1;
                $this->lastSector += $sectors;
            }
            fseek($this->filePointer, $this->locationTable[$i][0] << 12);
            fwrite($this->filePointer, str_pad($chunk, $sectors << 12, "\x00", STR_PAD_RIGHT));
        }
        $this->writeLocationTable();
        $n = $this->cleanGarbage();
        $this->writeLocationTable();

        return $n;
    }


    /**
     * @return int
     */
    private function cleanGarbage()
    {
        $sectors = [];
        foreach ($this->locationTable as $index => $data) {//Calculate file usage
            if ($data[0] === 0 or $data[1] === 0) {
                $this->locationTable[$index] = [0, 0, 0];
                continue;
            }
            for ($i = 0; $i < $data[1]; ++$i)
                $sectors[$data[0]] = $index;
        }

        if (count($sectors) == ($this->lastSector - 2))//No collection needed
            return 0;

        ksort($sectors);
        $shift = 0;
        $lastSector = 1;//First chunk - 1

        fseek($this->filePointer, 8192);
        $sector = 2;
        foreach ($sectors as $sector => $index) {
            if (($sector - $lastSector) > 1)
                $shift += $sector - $lastSector - 1;
            if ($shift > 0) {
                fseek($this->filePointer, $sector << 12);
                $old = fread($this->filePointer, 4096);
                fseek($this->filePointer, ($sector - $shift) << 12);
                fwrite($this->filePointer, $old, 4096);
            }
            $this->locationTable[$index][0] -= $shift;
            $lastSector = $sector;
        }
        ftruncate($this->filePointer, ($sector + 1) << 12);//Truncate to the end of file written
        return $shift;
    }


    private function loadLocationTable()
    {
        fseek($this->filePointer, 0);
        $this->lastSector = 1;

        $data = unpack('N*', fread($this->filePointer, 4 * 1024 * 2));//1024 records * 4 bytes * 2 times
        for ($i = 0; $i < 1024; ++$i) {
            $index = $data[$i + 1];
            $this->locationTable[$i] = [$index >> 8, $index & 0xff, $data[1024 + $i + 1]];
            if (($this->locationTable[$i][0] + $this->locationTable[$i][1] - 1) > $this->lastSector)
                $this->lastSector = $this->locationTable[$i][0] + $this->locationTable[$i][1] - 1;
        }
    }


    private function writeLocationTable()
    {
        $write = [];

        for ($i = 0; $i < 1024; ++$i)
            $write[] = (($this->locationTable[$i][0] << 8) | $this->locationTable[$i][1]);
        for ($i = 0; $i < 1024; ++$i)
            $write[] = $this->locationTable[$i][2];
        fseek($this->filePointer, 0);
        fwrite($this->filePointer, pack("N*", ...$write), 4096 * 2);
    }


    private function writeLocationIndex(int $index = 0)
    {
        fseek($this->filePointer, $index << 2);
        fwrite($this->filePointer, Binary::writeInt(($this->locationTable[$index][0] << 8) | $this->locationTable[$index][1]), 4);
        fseek($this->filePointer, 4096 + ($index << 2));
        fwrite($this->filePointer, Binary::writeInt($this->locationTable[$index][2]), 4);
    }

    public function getX() : int
    {
        return (int)$this->x;
    }

    public function getZ() : int
    {
        return (int)$this->z;
    }

    public function getPath() : string
    {
        return (string)$this->filePath;
    }
}