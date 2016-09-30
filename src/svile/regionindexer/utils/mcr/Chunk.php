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


use svile\regionindexer\utils\nbt\NBT;

use svile\regionindexer\utils\nbt\tag\CompoundTag;
use svile\regionindexer\utils\nbt\tag\IntTag;
use svile\regionindexer\utils\nbt\tag\ListTag;
use svile\regionindexer\utils\nbt\tag\ByteArrayTag;


final class Chunk
{
    /** @var CompoundTag */
    private $nbt;

    public function __construct(CompoundTag $nbt)
    {
        if ($nbt->getName() != 'Level')
            $nbt = new CompoundTag('Level', []);

        $this->nbt = $nbt;

        if (!isset($this->nbt->Entities) || !($this->nbt->Entities instanceof ListTag))
            $this->nbt->Entities = new ListTag('Entities', []);
        $this->nbt->Entities->setTagType(NBT::TAG_Compound);

        if (!isset($this->nbt->TileEntities) || !($this->nbt->TileEntities instanceof ListTag))
            $this->nbt->TileEntities = new ListTag('TileEntities', []);
        $this->nbt->TileEntities->setTagType(NBT::TAG_Compound);

        if (!isset($this->nbt->TileTicks) || !($this->nbt->TileTicks instanceof ListTag))
            $this->nbt->TileTicks = new ListTag('TileTicks', []);
        $this->nbt->TileTicks->setTagType(NBT::TAG_Compound);

        if (!isset($this->nbt->xPos) || !($this->nbt->xPos instanceof IntTag))
            $this->nbt->xPos = new IntTag('xPos', 0);

        if (!isset($this->nbt->zPos) || !($this->nbt->zPos instanceof IntTag))
            $this->nbt->zPos = new IntTag('zPos', 0);

        if (!isset($this->nbt->Blocks))
            $this->nbt->Blocks = new ByteArrayTag('Blocks', str_repeat("\x00", 32768));

        if (!isset($this->nbt->Data))
            $this->nbt->Data = new ByteArrayTag('Data', str_repeat("\x00", 16384));
    }


    /**
     * @param $data
     * @return null|Chunk
     */
    public static function fromBinary($data)
    {
        $nbt = new NBT(NBT::BIG_ENDIAN);

        try {
            $nbt->readCompressed($data, ZLIB_ENCODING_DEFLATE);
            $chunk = $nbt->getData();
            if (!isset($chunk->Level) || !($chunk->Level instanceof CompoundTag))
                return null;
            return new Chunk($chunk->Level);
        } catch (\Throwable $e) {
            return null;
        }
    }


    /**
     * @return bool|string
     */
    public function toBinary()
    {
        $nbt = clone $this->nbt;
        $writer = new NBT(NBT::BIG_ENDIAN);
        $nbt->setName('Level');
        $writer->setData(new CompoundTag('', ['Level' => $nbt]));

        return $writer->writeCompressed(ZLIB_ENCODING_DEFLATE);
    }


    public function getX() : int
    {
        return (int)$this->nbt['xPos'];
    }


    public function setX(int $x = 0)
    {
        $this->nbt->xPos = new IntTag('xPos', (int)$x);
    }


    public function getZ() : int
    {
        return (int)$this->nbt['zPos'];
    }


    public function setZ(int $z = 0)
    {
        $this->nbt->zPos = new IntTag('zPos', (int)$z);
    }

    public function getNbt() : CompoundTag
    {
        return $this->nbt;
    }
}