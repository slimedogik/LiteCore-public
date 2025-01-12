<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Implementation of MCPE-style chunks with subchunks with XZY ordering.
 */
declare(strict_types = 1);

namespace pocketmine\level\format;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\XPOrb;
use pocketmine\level\format\io\ChunkException;
use pocketmine\level\Level;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\tile\Spawnable;
use pocketmine\tile\Tile;
use pocketmine\utils\BinaryStream;
use function array_fill;
use function array_filter;
use function array_values;
use function assert;
use function chr;
use function count;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function unpack;

class Chunk{

    public const MAX_SUBCHUNKS = 16;

    /** @var int */
    protected $x;
    /** @var int */
    protected $z;

    /** @var bool */
    protected $hasChanged = false;

    /** @var bool */
    protected $isInit = false;

    /** @var bool */
    protected $lightPopulated = false;
    /** @var bool */
    protected $terrainGenerated = false;
    /** @var bool */
    protected $terrainPopulated = false;

    /** @var int */
    protected $height = Chunk::MAX_SUBCHUNKS;

    /** @var SubChunk[] */
    protected $subChunks;

    /** @var EmptySubChunk */
    protected $emptySubChunk;

    /** @var Tile[] */
    protected $tiles = [];
    /** @var Tile[] */
    protected $tileList = [];

    /** @var Entity[] */
    protected $entities = [];

    /**
     * @var \SplFixedArray|int[]
     * @phpstan-var \SplFixedArray<int>
     */
	protected $heightMap;

    /** @var string */
    protected $biomeIds;

    protected $extraData = [];

    /** @var CompoundTag[] */
    protected $NBTtiles = [];

    /** @var CompoundTag[] */
    protected $NBTentities = [];

    /**
     * @param SubChunkInterface[] $subChunks
     * @param CompoundTag[]       $entities
     * @param CompoundTag[]       $tiles
     * @param int[]               $heightMap
     * @phpstan-param list<int> $heightMap
     */
    public function __construct(int $chunkX, int $chunkZ, array $subChunks = [], array $entities = [], array $tiles = [], string $biomeIds = "", array $heightMap = []){
        $this->x = $chunkX;
        $this->z = $chunkZ;

        $this->height = Chunk::MAX_SUBCHUNKS; //TODO: add a way of changing this

        $this->emptySubChunk = EmptySubChunk::getInstance();

        foreach($subChunks as $y => $subChunk){
            if($y < 0 or $y >= $this->height){
                throw new ChunkException("Invalid subchunk index $y!");
            }
            if($subChunk->isEmpty()){
                $this->subChunks[$y] = $this->emptySubChunk;
            }else{
                $this->subChunks[$y] = $subChunk;
            }
        }

        for($i = 0; $i < $this->height; ++$i){
            if(!isset($this->subChunks[$i])){
                $this->subChunks[$i] = $this->emptySubChunk;
            }
        }

        if(count($heightMap) === 256){
            $this->heightMap = \SplFixedArray::fromArray($heightMap);
        }else{
            assert(count($heightMap) === 0, "Wrong HeightMap value count, expected 256, got " . count($heightMap));
            $val = ($this->height * 16);
            $this->heightMap = \SplFixedArray::fromArray(array_fill(0, 256, $val));
        }

        if(strlen($biomeIds) === 256){
            $this->biomeIds = $biomeIds;
        }else{
            assert(strlen($biomeIds) === 0, "Wrong BiomeIds value count, expected 256, got " . strlen($biomeIds));
            $this->biomeIds = str_repeat("\x00", 256);
        }

        $this->NBTtiles = $tiles;
        $this->NBTentities = $entities;
    }

    /**
     * @return int
     */
    public function getX() : int{
        return $this->x;
    }

    /**
     * @return int
     */
    public function getZ() : int{
        return $this->z;
    }

    public function setX(int $x){
        $this->x = $x;
    }

    /**
     * @param int $z
     */
    public function setZ(int $z){
        $this->z = $z;
    }

    /**
     * Returns the chunk height in count of subchunks.
     *
     * @return int
     */
    public function getHeight() : int{
        return $this->height;
    }

    /**
     * Returns a bitmap of block ID and meta at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return int bitmap, (id << 4) | meta
     */
    public function getFullBlock(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getFullBlock($x, $y & 0x0f, $z);
    }

    /**
     * Sets block ID and meta in one call at the specified chunk block coordinates
     *
     * @param int      $x 0-15
     * @param int      $y
     * @param int      $z 0-15
     * @param int|null $blockId 0-255 if null, does not change
     * @param int|null $meta 0-15 if null, does not change
     *
     * @return bool
     */
    public function setBlock(int $x, int $y, int $z, $blockId = null, $meta = null) : bool{
        if($this->getSubChunk($y >> 4, true)->setBlock($x, $y & 0x0f, $z, $blockId !== null ? ($blockId & 0xff) : null, $meta !== null ? ($meta & 0x0f) : null)){
            $this->hasChanged = true;
            return true;
        }
        return false;
    }

    /**
     * Returns the block ID at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return int 0-255
     */
    public function getBlockId(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockId($x, $y & 0x0f, $z);
    }

    /**
     * Sets the block ID at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     * @param int $id 0-255
     */
    public function setBlockId(int $x, int $y, int $z, int $id){
        if($this->getSubChunk($y >> 4, true)->setBlockId($x, $y & 0x0f, $z, $id)){
            $this->hasChanged = true;
        }
    }

    /**
     * Returns the block meta value at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return int 0-15
     */
    public function getBlockData(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockData($x, $y & 0x0f, $z);
    }

    /**
     * Sets the block meta value at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     * @param int $data 0-15
     */
    public function setBlockData(int $x, int $y, int $z, int $data){
        if($this->getSubChunk($y >> 4, true)->setBlockData($x, $y & 0x0f, $z, $data)){
            $this->hasChanged = true;
        }
    }

    /**
     * Returns the raw block extra data value at the specified chunk block coordinates, or 0 if no data exists
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return int bitmap, (meta << 8) | id
     */
    public function getBlockExtraData(int $x, int $y, int $z) : int{
        return $this->extraData[Chunk::chunkBlockHash($x, $y, $z)] ?? 0;
    }

    /**
     * Sets the raw block extra data value at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     * @param int $data bitmap, (meta << 8) | id
     */
    public function setBlockExtraData(int $x, int $y, int $z, int $data){
        if($data === 0){
            unset($this->extraData[Chunk::chunkBlockHash($x, $y, $z)]);
        }else{
            $this->extraData[Chunk::chunkBlockHash($x, $y, $z)] = $data;
        }

        $this->hasChanged = true;
    }

    /**
     * Returns the sky light level at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return int 0-15
     */
    public function getBlockSkyLight(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockSkyLight($x, $y & 0x0f, $z);
    }

    /**
     * Sets the sky light level at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     * @param int $level 0-15
     */
    public function setBlockSkyLight(int $x, int $y, int $z, int $level){
        if($this->getSubChunk($y >> 4, true)->setBlockSkyLight($x, $y & 0x0f, $z, $level)){
            $this->hasChanged = true;
        }
    }

    /**
     * @param int $level
     */
    public function setAllBlockSkyLight(int $level){
        $char = chr(($level & 0x0f) | ($level << 4));
        $data = str_repeat($char, 2048);
        for($y = $this->getHighestSubChunkIndex(); $y >= 0; --$y){
            $this->getSubChunk($y, true)->setBlockSkyLightArray($data);
        }
    }

    /**
     * Returns the block light level at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y 0-15
     * @param int $z 0-15
     *
     * @return int 0-15
     */
    public function getBlockLight(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockLight($x, $y & 0x0f, $z);
    }

    /**
     * Sets the block light level at the specified chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $y 0-15
     * @param int $z 0-15
     * @param int $level 0-15
     */
    public function setBlockLight(int $x, int $y, int $z, int $level){
        if($this->getSubChunk($y >> 4, true)->setBlockLight($x, $y & 0x0f, $z, $level)){
            $this->hasChanged = true;
        }
    }

    /**
     * @param int $level
     */
    public function setAllBlockLight(int $level){
        $char = chr(($level & 0x0f) | ($level << 4));
        $data = str_repeat($char, 2048);
        for($y = $this->getHighestSubChunkIndex(); $y >= 0; --$y){
            $this->getSubChunk($y, true)->setBlockLightArray($data);
        }
    }

    /**
     * Returns the Y coordinate of the highest non-air block at the specified X/Z chunk block coordinates
     *
     * @param int  $x 0-15
     * @param int  $z 0-15
     *
     * @return int 0-255, or -1 if there are no blocks in the column
     */
    public function getHighestBlockAt(int $x, int $z) : int{
        $index = $this->getHighestSubChunkIndex();
        if($index === -1){
            return -1;
        }

        for($y = $index; $y >= 0; --$y){
            $height = $this->getSubChunk($y)->getHighestBlockAt($x, $z) | ($y << 4);
            if($height !== -1){
                return $height;
            }
        }

        return -1;
    }

    public function getMaxY() : int{
        return ($this->getHighestSubChunkIndex() << 4) | 0x0f;
    }

    /**
     * Returns the heightmap value at the specified X/Z chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return int
     */
    public function getHeightMap(int $x, int $z) : int{
        return $this->heightMap[($z << 4) | $x];
    }

    /**
     * Returns the heightmap value at the specified X/Z chunk block coordinates
     * @param int $x 0-15
     * @param int $z 0-15
     * @param int $value
     */
    public function setHeightMap(int $x, int $z, int $value){
        $this->heightMap[($z << 4) | $x] = $value;
    }

    /**
     * Recalculates the heightmap for the whole chunk.
     *
     * @return void
     */
    public function recalculateHeightMap(){
        for($z = 0; $z < 16; ++$z){
            for($x = 0; $x < 16; ++$x){
                $this->recalculateHeightMapColumn($x, $z);
            }
        }
    }

    /**
     * Recalculates the heightmap for the block column at the specified X/Z chunk coordinates
     *
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return int New calculated heightmap value (0-256 inclusive)
     */
    public function recalculateHeightMapColumn(int $x, int $z) : int{
        $y = $this->getHighestBlockAt($x, $z);
        for(; $y >= 0; --$y){
            if(Block::$lightFilter[$id = $this->getBlockId($x, $y, $z)] > 1 or Block::$diffusesSkyLight[$id]){
                break;
            }
        }
        $this->setHeightMap($x, $z, $y + 1);
        return $y + 1;
    }

    /**
     * Performs basic sky light population on the chunk.
     * This does not cater for adjacent sky light, this performs direct sky light population only. This may cause some strange visual artifacts
     * if the chunk is light-populated after being terrain-populated.
     *
     * TODO: fast adjacent light spread
     */
    public function populateSkyLight(){
        $maxY = $this->getMaxY();

        $this->setAllBlockSkyLight(0);

        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                $y = $maxY;
                $heightMap = $this->getHeightMap($x, $z);
                
                for(; $y >= $heightMap; --$y){
                    $this->setBlockSkyLight($x, $y, $z, 15);
                }

                $light = 15;
                for(; $y >= 0; --$y){
                    $light -= Block::$lightFilter[$this->getBlockId($x, $y, $z)];
					if($light <= 0){
						break;
                    }
                    $this->setBlockSkyLight($x, $y, $z, $light);
                }
            }
        }
    }

    /**
     * Returns the biome ID at the specified X/Z chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return int 0-255
     */
    public function getBiomeId(int $x, int $z) : int{
        return ord($this->biomeIds[($z << 4) | $x]);
    }

    /**
     * Sets the biome ID at the specified X/Z chunk block coordinates
     *
     * @param int $x 0-15
     * @param int $z 0-15
     * @param int $biomeId 0-255
     */
    public function setBiomeId(int $x, int $z, int $biomeId){
        $this->hasChanged = true;
        $this->biomeIds[($z << 4) | $x] = chr($biomeId & 0xff);
    }

    /**
     * Returns a column of block IDs from bottom to top at the specified X/Z chunk block coordinates.
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return string
     */
    public function getBlockIdColumn(int $x, int $z) : string{
        $result = "";
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockIdColumn($x, $z);
        }
        return $result;
    }

    /**
     * Returns a column of block meta values from bottom to top at the specified X/Z chunk block coordinates.
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return string
     */
    public function getBlockDataColumn(int $x, int $z) : string{
        $result = "";
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockDataColumn($x, $z);
        }
        return $result;
    }

    /**
     * Returns a column of sky light values from bottom to top at the specified X/Z chunk block coordinates.
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return string
     */
    public function getBlockSkyLightColumn(int $x, int $z) : string{
        $result = "";
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getSkyLightColumn($x, $z);
        }
        return $result;
    }

    /**
     * Returns a column of block light values from bottom to top at the specified X/Z chunk block coordinates.
     * @param int $x 0-15
     * @param int $z 0-15
     *
     * @return string
     */
    public function getBlockLightColumn(int $x, int $z) : string{
        $result = "";
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockLightColumn($x, $z);
        }
        return $result;
    }

    /**
     * @return bool
     */
    public function isLightPopulated() : bool{
        return $this->lightPopulated;
    }

    /**
     * @return void
     */
    public function setLightPopulated(bool $value = true){
        $this->lightPopulated = $value;
        $this->hasChanged = true;
    }

    public function isPopulated() : bool{
        return $this->terrainPopulated;
    }

    /**
     * @return void
     */
    public function setPopulated(bool $value = true){
        $this->terrainPopulated = $value;
        $this->hasChanged = true;
    }

    public function isGenerated() : bool{
        return $this->terrainGenerated;
    }

    /**
     * @return void
     */
    public function setGenerated(bool $value = true){
        $this->terrainGenerated = $value;
        $this->hasChanged = true;
    }

    /**
     * @param Entity $entity
     */
    public function addEntity(Entity $entity){
    	if($entity->isClosed() and !($entity instanceof XPOrb)){ //TODO: очень тупой костыль
            throw new \InvalidArgumentException("Attempted to add a garbage closed Entity to a chunk");
        }
        $this->entities[$entity->getId()] = $entity;
        if(!($entity instanceof Player) and $this->isInit){
            $this->hasChanged = true;
        }
    }

    /**
     * @param Entity $entity
     */
    public function removeEntity(Entity $entity){
        unset($this->entities[$entity->getId()]);
        if(!($entity instanceof Player) and $this->isInit){
            $this->hasChanged = true;
        }
    }

    /**
     * @param Tile $tile
     */
    public function addTile(Tile $tile){
    	if($tile->isClosed()){
            throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to a chunk");
        }
        $this->tiles[$tile->getId()] = $tile;
        if(isset($this->tileList[$index = (($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]) and $this->tileList[$index] !== $tile){
            $this->tileList[$index]->close();
        }
        $this->tileList[$index] = $tile;
        if($this->isInit){
            $this->hasChanged = true;
        }
    }

    /**
     * @param Tile $tile
     */
    public function removeTile(Tile $tile){
        unset($this->tiles[$tile->getId()]);
        unset($this->tileList[(($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]);
        if($this->isInit){
            $this->hasChanged = true;
        }
    }

    /**
     * Returns an array of entities currently using this chunk.
     *
     * @return Entity[]
     */
    public function getEntities() : array{
        return $this->entities;
    }

    /**
     * @return Entity[]
     */
    public function getSavableEntities() : array{
        return array_filter($this->entities, function(Entity $entity) : bool{ return $entity->canSaveWithChunk() and !$entity->isClosed(); });
    }

    /**
     * @return Tile[]
     */
    public function getTiles() : array{
        return $this->tiles;
    }

    /**
     * Called when the chunk is unloaded, closing entities and tiles.
     */
    public function onUnload() : void{
        foreach($this->getEntities() as $entity){
            if($entity instanceof Player){
                continue;
            }
            $entity->close();
        }

        foreach($this->getTiles() as $tile){
            $tile->close();
        }
    }

    /**
     * Returns the tile at the specified chunk block coordinates, or null if no tile exists.
     *
     * @param int $x 0-15
     * @param int $y
     * @param int $z 0-15
     *
     * @return Tile|null
     */
    public function getTile(int $x, int $y, int $z){
        $index = ($x << 12) | ($z << 8) | $y;
        return $this->tileList[$index] ?? null;
    }

    /**
     * Unloads the chunk, closing entities and tiles.
     */
    public function unload() : void{
        foreach($this->getEntities() as $entity){
            if($entity instanceof Player){
                continue;
            }
            $entity->close();
        }

        foreach($this->getTiles() as $tile){
            $tile->close();
        }
    }

    /**
     * Deserializes tiles and entities from NBT
     *
     * @return void
     */
    public function initChunk(Level $level){
        if(!$this->isInit){
            $changed = false;

            $level->timings->syncChunkLoadEntitiesTimer->startTiming();
            foreach($this->NBTentities as $nbt){
                if($nbt instanceof CompoundTag){
                    if(!isset($nbt->id)){
                        $changed = true;
                        continue;
                    }

                    if(($nbt["Pos"][0] >> 4) !== $this->x or ($nbt["Pos"][2] >> 4) !== $this->z){
                        $changed = true;
                        continue; //Fixes entities allocated in wrong chunks.
                    }

                    if(!(($entity = Entity::createEntity($nbt["id"], $level, $nbt)) instanceof Entity)){
                        $changed = true;
                        continue;
                    }
                }
            }
            $this->NBTentities = [];
            $level->timings->syncChunkLoadEntitiesTimer->stopTiming();

            $level->timings->syncChunkLoadTileEntitiesTimer->startTiming();
            foreach($this->NBTtiles as $nbt){
                if($nbt instanceof CompoundTag){
                    if(!isset($nbt->id)){
                        $changed = true;
                        continue;
                    }

                    if(($nbt["x"] >> 4) !== $this->x or ($nbt["z"] >> 4) !== $this->z){
                        $changed = true;
                        continue; //Fixes tiles allocated in wrong chunks.
                    }

                    if(Tile::createTile($nbt["id"], $level, $nbt) === null){
                        $changed = true;
                        continue;
                    }
                }
            }

            $this->NBTtiles = [];
            $level->timings->syncChunkLoadTileEntitiesTimer->stopTiming();

            $this->hasChanged = $changed;

            $this->isInit = true;
        }
    }

    public function getBiomeIdArray() : string{
        return $this->biomeIds;
    }

    /**
     * @return int[]
     */
    public function getHeightMapArray() : array{
        return $this->heightMap->toArray();
    }

    /**
     * @return int[]
     */
    public function getBlockExtraDataArray() : array{
        return $this->extraData;
    }

    public function hasChanged() : bool{
        return $this->hasChanged;
    }

    /**
     * @param bool $value
     */
    public function setChanged(bool $value = true){
        $this->hasChanged = $value;
    }

    /**
     * Returns the subchunk at the specified subchunk Y coordinate, or an empty, unmodifiable stub if it does not exist or the coordinate is out of range.
     *
     * @param int  $y
     * @param bool $generateNew Whether to create a new, modifiable subchunk if there is not one in place
     *
     * @return SubChunk|EmptySubChunk
     */
    public function getSubChunk(int $y, bool $generateNew = false) : SubChunk{
        if($y < 0 or $y >= $this->height){
            return $this->emptySubChunk;
        }elseif($generateNew and $this->subChunks[$y] instanceof EmptySubChunk){
            $this->subChunks[$y] = new SubChunk();
        }
        assert($this->subChunks[$y] !== null, "Somehow something broke, no such subchunk at index $y");

        return $this->subChunks[$y];
    }

    /**
     * Sets a subchunk in the chunk index
     * @param int           $y
     * @param SubChunk|null $subChunk
     * @param bool          $allowEmpty Whether to check if the chunk is empty, and if so replace it with an empty stub
     *
     * @return bool
     */
    public function setSubChunk(int $y, SubChunk $subChunk = null, bool $allowEmpty = false) : bool{
        if($y < 0 or $y >= $this->height){
            return false;
        }
        if($subChunk === null or ($subChunk->isEmpty() and !$allowEmpty)){
            $this->subChunks[$y] = $this->emptySubChunk;
        }else{
            $this->subChunks[$y] = $subChunk;
        }
        $this->hasChanged = true;
        return true;
    }

    /**
     * @return SubChunk[]
     */
    public function getSubChunks() : array{
        return $this->subChunks;
    }

    /**
     * Returns the Y coordinate of the highest non-empty subchunk in this chunk.
     */
    public function getHighestSubChunkIndex() : int{
        for($y = count($this->subChunks) - 1; $y >= 0; --$y){
            if($this->subChunks[$y] === null or $this->subChunks[$y] instanceof EmptySubChunk){
                //No need to thoroughly prune empties at runtime, this will just reduce performance.
                continue;
            }
            return $y;
        }

        return -1;
    }

    /**
     * Returns the count of subchunks that need sending to players
     */
    public function getSubChunkSendCount() : int{
        return $this->getHighestSubChunkIndex() + 1;
    }

    /**
     * Disposes of empty subchunks and frees data where possible
     */
    public function collectGarbage() : void{
        foreach($this->subChunks as $y => $subChunk){
            if($subChunk instanceof SubChunk){
                if($subChunk->isEmpty()){
                    $this->subChunks[$y] = $this->emptySubChunk;
                }else{
                    $subChunk->collectGarbage();
                }
            }
        }
    }

    /**
     * Serializes the chunk for sending to players
     */
    public function networkSerialize() : string{
        $result = "";
        $subChunkCount = $this->getSubChunkSendCount();
        $result .= chr($subChunkCount);
        for($y = 0; $y < $subChunkCount; ++$y){
            $result .= $this->subChunks[$y]->networkSerialize();
        }
        $result .= pack("v*", ...$this->heightMap)
            .  $this->biomeIds
            .  chr(0); //border block array count
        //Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.

        $extraData = new BinaryStream();
        $extraData->putVarInt(count($this->extraData)); //WHY, Mojang, WHY
        foreach($this->extraData as $key => $value){
            $extraData->putVarInt($key);
            $extraData->putLShort($value);
        }
        $result .= $extraData->getBuffer();

        foreach($this->tiles as $tile){
			if($tile instanceof Spawnable){
				$result .= $tile->getSerializedSpawnCompound();
            }
        }

        return $result;
    }

   /**
     * Fast-serializes the chunk for passing between threads
     * TODO: tiles and entities
     */
    public function fastSerialize() : string{
        $stream = new BinaryStream();
        $stream->putInt($this->x);
        $stream->putInt($this->z);
        $stream->putByte(($this->lightPopulated ? 4 : 0) | ($this->terrainPopulated ? 2 : 0) | ($this->terrainGenerated ? 1 : 0));
        if($this->terrainGenerated){
            //subchunks
            $count = 0;
            $subChunks = "";
            foreach($this->subChunks as $y => $subChunk){
                if($subChunk instanceof EmptySubChunk){
                    continue;
                }
                ++$count;
                $subChunks .= chr($y) . $subChunk->getBlockIdArray() . $subChunk->getBlockDataArray();
                if($this->lightPopulated){
                    $subChunks .= $subChunk->getSkyLightArray() . $subChunk->getBlockLightArray();
                }
            }
            $stream->putByte($count);
            $stream->put($subChunks);

            //biomes
            $stream->put($this->biomeIds);
            if($this->lightPopulated){
                $stream->put(pack("v*", ...$this->heightMap));
            }
        }

        return $stream->getBuffer();
    }

    /**
     * Deserializes a fast-serialized chunk
     */
    public static function fastDeserialize(string $data) : Chunk{
        $stream = new BinaryStream($data);
        
        $x = $stream->getInt();
        $z = $stream->getInt();
        $flags = $stream->getByte();
        $lightPopulated = (bool) ($flags & 4);
        $terrainPopulated = (bool) ($flags & 2);
        $terrainGenerated = (bool) ($flags & 1);

        $subChunks = [];
        $biomeIds = "";
        $heightMap = [];
        if($terrainGenerated){
            $count = $stream->getByte();
            for($y = 0; $y < $count; ++$y){
                $subChunks[$stream->getByte()] = new SubChunk(
                    $stream->get(4096), //blockids
                    $stream->get(2048), //blockdata
                    $lightPopulated ? $stream->get(2048) : "", //skylight
                    $lightPopulated ? $stream->get(2048) : "" //blocklight
                );
            }

            $biomeIds = $stream->get(256);
            if($lightPopulated){
                /** @var int[] $unpackedHeightMap */
                $unpackedHeightMap = unpack("v*", $stream->get(512)); //unpack() will never fail here
                $heightMap = array_values($unpackedHeightMap);
            }
        }

        $chunk = new Chunk($x, $z, $subChunks, [], [], $biomeIds, $heightMap);
        $chunk->setGenerated($terrainGenerated);
        $chunk->setPopulated($terrainPopulated);
        $chunk->setLightPopulated($lightPopulated);
        $chunk->setChanged(false);
        
        return $chunk;
    }

    /**
     * Creates a block hash from chunk block coordinates. Used for extra data keys in chunk packets.
     * @internal
     *
     * @param int $x 0-15
     * @param int $y 0-255
     * @param int $z 0-15
     *
     * @return int
     */
    public static function chunkBlockHash(int $x, int $y, int $z) : int{
        return ($x << 12) | ($z << 8) | $y;
    }

}