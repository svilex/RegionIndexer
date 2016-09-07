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

namespace svile\regionindexer;


use svile\regionindexer\utils\console\Console;
use svile\regionindexer\utils\mcr\Region;

use svile\regionindexer\utils\nbt\NBT;
use svile\regionindexer\utils\nbt\tag\CompoundTag;
use svile\regionindexer\utils\nbt\tag\IntTag;
use svile\regionindexer\utils\nbt\tag\StringTag;


final class Indexer
{
    /** @var string */
    private $path = '';
    /** @var CompoundTag */
    private $levelData;
    /** @var int[] */
    private $spawnXZ = [0, 0];// [ SpawnX, SpawnZ ] from the level.dat
    /** @var array */
    private $regions = [];// [ Rx, Rz, RegionPath ] [ ]


    public function __construct(string $path, array $regions = [])
    {
        $this->path = $path;
        $this->regions = $regions;

        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->readCompressed(file_get_contents($path . '/level.dat'));
        $levelData = $nbt->getData();
        if (isset($levelData->Data, $levelData->Data->SpawnX, $levelData->Data->SpawnZ, $levelData->Data->LevelName) &&
            $levelData->Data instanceof CompoundTag &&
            $levelData->Data->SpawnX instanceof IntTag &&
            $levelData->Data->SpawnZ instanceof IntTag &&
            $levelData->Data->LevelName instanceof StringTag
        ) {
            $this->spawnXZ = [(int)$levelData->Data['SpawnX'], (int)$levelData->Data['SpawnZ']];
            Console::info('§aRe-indexing §f§r' . $levelData->Data['LevelName'] . ' §afrom §b' . $this->spawnXZ[0] . '§f§r, §b' . $this->spawnXZ[1]);
            $this->levelData = $levelData->Data;
        } else {
            Console::error('§cInvalid level.dat');
            exit(0);
        }
        unset($levelData);

        $this->run();
    }


    private function run()
    {
        $diffX = -($this->spawnXZ[0] >> 9);
        $diffZ = -($this->spawnXZ[1] >> 9);
        foreach ($this->regions as $region) {
            $r = new Region($region[0], $region[1], $region[2]);
            $this->indexRegion($r, $diffX, $diffZ);
            $r->close();
        }
        $this->levelData->SpawnX = new IntTag("SpawnX", (int)(($diffX << 9) + $this->spawnXZ[0]));
        $this->levelData->SpawnZ = new IntTag("SpawnZ", (int)(($diffZ << 9) + $this->spawnXZ[1]));
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->setData(new CompoundTag("", [
            "Data" => $this->levelData
        ]));
        $buffer = $nbt->writeCompressed();
        if (@file_put_contents($this->path . "/level.dat", $buffer))
            Console::info('§aMoved §f§r' . $this->levelData['LevelName'] . ' §a\'s spawn to §b' . $this->levelData['SpawnX'] . '§f§r, §b' . $this->levelData['SpawnZ']);
        else
            Console::error('§cCould\'t save the new spawn in the level.dat, you can try teleporting yourself at: §b' . $this->levelData['SpawnX'] . '§f§r, §b' . $this->levelData['SpawnZ']);
        unset($nbt, $this->levelData, $buffer);
    }


    private function indexRegion(Region $region, int $diffX = 0, int $diffZ = 0)
    {
    }
}