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

namespace svile\regionindexer\utils\nbt\tag;


use svile\regionindexer\utils\nbt\NBT;


class ShortTag extends NamedTag
{
    public function getType()
    {
        return NBT::TAG_Short;
    }


    public function read(NBT $nbt)
    {
        $this->value = $nbt->getShort();
    }


    public function write(NBT $nbt)
    {
        $nbt->putShort($this->value);
    }
}