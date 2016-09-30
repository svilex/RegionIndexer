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
use svile\regionindexer\utils\nbt\tag\ListTag;
use svile\regionindexer\utils\nbt\tag\DoubleTag;
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
        if (abs($diffX) < 1 || abs($diffZ) < 1) {// == 0
            Console::error('§cWhat should i index? The spawn is already in region §f§rr.0.0.mcr');
            exit(0);
        }
        @mkdir($this->path . '/indexed_region', 0777, true);
        foreach ($this->regions as $region) {
            $new_path = $this->path . '/indexed_region/r.' . ($region[0] + $diffX) . '.' . ($region[1] + $diffZ) . '.mcr';
            if (copy($region[2], $new_path) && is_file($new_path)) {
                usleep(1000);
                $r = new Region($region[0], $region[1], $new_path);
                $this->indexRegion($r, $diffX, $diffZ);
                $r->close();
            }
        }
        $this->levelData->SpawnX = new IntTag('SpawnX', (int)(($diffX << 9) + $this->spawnXZ[0]));
        $this->levelData->SpawnZ = new IntTag('SpawnZ', (int)(($diffZ << 9) + $this->spawnXZ[1]));
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->setData(new CompoundTag('', [
            'Data' => $this->levelData
        ]));
        $buffer = $nbt->writeCompressed();
        if (@file_put_contents($this->path . '/indexed_level.dat', $buffer))
            Console::info('§aMoved §f§r' . $this->levelData['LevelName'] . ' §a\'s spawn to §b' . $this->levelData['SpawnX'] . '§f§r, §b' . $this->levelData['SpawnZ']);
        else
            Console::error('§cCould\'t save the new spawn in the indexed_level.dat, you can try teleporting yourself at: §b' . $this->levelData['SpawnX'] . '§f§r, §b' . $this->levelData['SpawnZ']);
        unset($nbt, $this->levelData, $buffer);
    }


    private function indexRegion(Region $region, int $diffX = 0, int $diffZ = 0)
    {
        for ($x = 0; $x < 32; $x++) {
            for ($z = 0; $z < 32; $z++) {
                if (($chunk = $region->readChunk($x, $z)) === null)
                    continue;
                if (($chunk->getX() - ($region->getX() << 5)) !== $x || ($chunk->getZ() - ($region->getZ() << 5)) !== $z)
                    Console::error('§cWrong chunk index');

                foreach ($chunk->getNbt()->TileEntities as &$nbt) {
                    if ($nbt instanceof CompoundTag) {
                        if (!isset($nbt->id, $nbt->x, $nbt->z))
                            continue;
                        if (($nbt['x'] >> 4) == $chunk->getX() && ($nbt['z'] >> 4) == $chunk->getZ()) {
                            $nbt->x = new IntTag('x', (int)(($diffX << 9) + $nbt['x']));
                            $nbt->z = new IntTag('z', (int)(($diffZ << 9) + $nbt['z']));
                        }
                    }
                }

                foreach ($chunk->getNbt()->Entities as &$nbt) {
                    if ($nbt instanceof CompoundTag) {
                        if (!isset($nbt->id, $nbt->x, $nbt->z))
                            continue;
                        if ((int)($nbt['Pos'][0] >> 4) == $chunk->getX() && (int)($nbt['Pos'][2] >> 4) == $chunk->getZ()) {
                            $nbt->Pos = new ListTag("Pos", [
                                new DoubleTag(0, ($diffX << 9) + $nbt['Pos'][0]),
                                new DoubleTag(1, $nbt['Pos'][1]),
                                new DoubleTag(2, ($diffX << 9) + $nbt['Pos'][2])
                            ]);
                        }
                    }
                }

                $chunk->setX(($diffX << 5) + $chunk->getX());
                $chunk->setZ(($diffZ << 5) + $chunk->getZ());

                if (($chunkData = $chunk->toBinary()) !== false)
                    $region->writeChunk($chunkData, $x, $z);
            }
        }
    }
}